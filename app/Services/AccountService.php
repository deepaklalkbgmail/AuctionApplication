<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AccountException;
use Database;
use PDO;

/**
 * =====================================================================
 *  Accounts — registration, approval, profiles and passwords
 * =====================================================================
 *
 *  The rule that shapes this class: a player's **name and email address are
 *  set once, at registration, and never change.** They are what the
 *  administrator approves and what the auction sheet is printed from, so
 *  letting a player edit them after approval would mean an approved
 *  identity could quietly become a different one.
 *
 *  Everything else about a player — mobile number, address, photo, what kind
 *  of cricketer they are — is theirs to keep up to date.
 *
 *  An administrator can change anything, including name and email, because
 *  someone has to be able to fix a genuine typo.
 */
final class AccountService
{
    /** Characters a secret code is built from. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const PHOTO_DIR = 'assets/img/uploads';

    private const MAX_PHOTO_BYTES = 3 * 1024 * 1024;

    /**
     * The kinds of cricketer, in one place, in the order they should be
     * offered and grouped everywhere: batting at one end, bowling at the
     * other, and the all-rounders in between.
     *
     * Three of those. A team buying a middle-order batter who bowls four
     * overs is not buying the same player as a fourth seamer who can bat,
     * so those two are named apart — but plain "all-rounder" stays for the
     * genuine article who is neither more one nor the other, and for the
     * registering player who does not want to claim a leaning.
     *
     * Anything reading a database row must accept only these keys; the
     * ENUMs on users.player_type and players.role hold the same six.
     */
    public const PLAYER_KINDS = [
        'batsman'             => 'Batsman',
        'batting_all_rounder' => 'Batting all-rounder',
        'all_rounder'         => 'All-rounder',
        'bowling_all_rounder' => 'Bowling all-rounder',
        'wicket_keeper'       => 'Wicket-keeper',
        'bowler'              => 'Bowler',
    ];

    // -----------------------------------------------------------------
    //  Registration
    // -----------------------------------------------------------------

    /**
     * A player registers themselves. They land in 'pending' and can do
     * nothing until an administrator approves them.
     *
     * @param array<string,mixed>      $in
     * @param array<string,mixed>|null $photo a $_FILES entry, optional
     * @return int the new user id
     */
    public function register(array $in, ?array $photo = null): int
    {
        [
            'name' => $name, 'email' => $email, 'username' => $username,
            'phone' => $phone, 'address' => $address, 'player_type' => $type,
            'password' => $password,
        ] = $this->validateRegistration($in);

        $photoPath = $photo !== null && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
            ? $this->storePhoto($photo)
            : null;

        Database::run(
            'INSERT INTO users
                (username, name, email, phone, address, photo_path, player_type,
                 password_hash, role, status)
             VALUES
                (:username, :name, :email, :phone, :address, :photo, :type,
                 :hash, :role, :status)',
            [
                ':username' => $username, ':name' => $name, ':email' => $email,
                ':phone' => $phone, ':address' => $address, ':photo' => $photoPath,
                ':type' => $type,
                ':hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                ':role' => 'player', ':status' => 'pending',
            ]
        );

        return Database::lastInsertId();
    }

    /**
     * Check a registration without creating anything.
     *
     * The registration form has a confirmation step — the player is shown
     * their name and email once more, because those two are permanent — and
     * that step needs to know the details are good *before* it can honestly
     * ask "is this right?". So validation lives here, and register() calls
     * the same method rather than repeating it.
     *
     * @param  array<string,mixed> $in
     * @return array<string,string> the cleaned values
     */
    public function validateRegistration(array $in): array
    {
        $clean = [
            'name'        => $this->text($in['name'] ?? '', 'Full name', 2, 120),
            'email'       => $this->email($in['email'] ?? ''),
            'username'    => $this->username($in['username'] ?? ''),
            'phone'       => $this->phone($in['phone'] ?? ''),
            'address'     => $this->text($in['address'] ?? '', 'Address', 5, 255),
            'player_type' => $this->playerType($in['player_type'] ?? ''),
            'password'    => (string) ($in['password'] ?? ''),
        ];

        $this->assertPasswordStrong($clean['password']);

        if ($clean['password'] !== (string) ($in['password_confirm'] ?? $clean['password'])) {
            throw new AccountException(AccountException::VALIDATION, 'The two passwords do not match.');
        }

        $this->assertEmailFree($clean['email']);
        $this->assertUsernameFree($clean['username']);

        return $clean;
    }

    /**
     * An administrator decides on a registration.
     *
     * @return array<string,mixed>
     */
    public function decideRegistration(int $userId, bool $approve, int $adminId): array
    {
        $user = $this->findUser($userId);

        if ($user['status'] !== 'pending') {
            throw new AccountException(
                AccountException::ALREADY_DECIDED,
                'That registration has already been ' . $user['status'] . '.'
            );
        }

        Database::exec(
            'UPDATE users SET status = :status, approved_by = :admin, approved_at = NOW() WHERE id = :id',
            [':status' => $approve ? 'approved' : 'rejected', ':admin' => $adminId, ':id' => $userId]
        );

        ActivityLog::record(
            $approve ? 'account.approve' : 'account.reject',
            'account',
            $userId,
            (string) $user['name'],
            ['status' => ['from' => $user['status'], 'to' => $approve ? 'approved' : 'rejected']],
            null,
            $user['email'] ?? null
        );

        return ['ok' => true, 'user_id' => $userId, 'status' => $approve ? 'approved' : 'rejected'];
    }

    // -----------------------------------------------------------------
    //  Profiles
    // -----------------------------------------------------------------

    /**
     * A player updates their own details.
     *
     * Name and email are not parameters here at all. That is the enforcement
     * — not a disabled input on the form, which any browser can re-enable.
     *
     * @param array<string,mixed>      $in
     * @param array<string,mixed>|null $photo
     */
    public function updateOwnProfile(int $userId, array $in, ?array $photo = null): void
    {
        $phone   = $this->phone($in['phone'] ?? '');
        $address = $this->text($in['address'] ?? '', 'Address', 5, 255);
        $type    = $this->playerType($in['player_type'] ?? '', allowEmpty: true);

        $set    = 'phone = :phone, address = :address';
        $params = [':phone' => $phone, ':address' => $address, ':id' => $userId];

        if ($type !== null) {
            $set .= ', player_type = :type';
            $params[':type'] = $type;
        }

        if ($photo !== null && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $set .= ', photo_path = :photo';
            $params[':photo'] = $this->storePhoto($photo);
        }

        Database::exec("UPDATE users SET {$set} WHERE id = :id", $params);
    }

    /**
     * An administrator updates anybody, including the fields a player cannot
     * touch — someone has to be able to correct a real mistake.
     *
     * @param array<string,mixed>      $in
     * @param array<string,mixed>|null $photo
     */
    public function adminUpdateUser(int $userId, array $in, ?array $photo = null): void
    {
        $user = $this->findUser($userId);

        $name  = $this->text($in['name'] ?? $user['name'], 'Full name', 2, 120);
        $email = $this->email($in['email'] ?? $user['email']);

        if (strcasecmp($email, (string) $user['email']) !== 0) {
            $this->assertEmailFree($email);
        }

        $set = 'name = :name, email = :email, phone = :phone, address = :address';
        $params = [
            ':name' => $name, ':email' => $email,
            ':phone'   => $this->phone($in['phone'] ?? ($user['phone'] ?? '')),
            ':address' => $this->text($in['address'] ?? ($user['address'] ?? ''), 'Address', 0, 255),
            ':id' => $userId,
        ];

        $type = $this->playerType($in['player_type'] ?? '', allowEmpty: true);
        if ($type !== null) {
            $set .= ', player_type = :type';
            $params[':type'] = $type;
        }

        if (!empty($in['status']) && in_array($in['status'], ['pending','approved','rejected','suspended'], true)) {
            $set .= ', status = :status';
            $params[':status'] = $in['status'];
        }

        if ($photo !== null && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $set .= ', photo_path = :photo';
            $params[':photo'] = $this->storePhoto($photo);
        }

        Database::exec("UPDATE users SET {$set} WHERE id = :id", $params);

        $changes = ActivityLog::diff(
            $user,
            (array) Database::one('SELECT * FROM users WHERE id = :id', [':id' => $userId]),
            ['name', 'email', 'phone', 'address', 'player_type', 'status', 'photo_path']
        );

        if ($changes !== []) {
            ActivityLog::record('account.update', 'account', $userId, $name, $changes);
        }
    }

    // -----------------------------------------------------------------
    //  Staff accounts
    // -----------------------------------------------------------------

    /** Staff roles that must name the tournament they work on. */
    public const TOURNAMENT_SCOPED_ROLES = ['scorer', 'tournament_admin'];

    /**
     * An administrator creates a scorer, a tournament administrator, or
     * another admin, and hands over the credentials. The account is
     * approved immediately — an administrator approving their own creation
     * would be theatre — but is forced to change the password at first
     * sign-in, so the issued one is never permanent.
     *
     * A scorer and a tournament administrator belong to ONE tournament and
     * must be given it here. That is what stops a scorer opening a match
     * from a season they have nothing to do with. Any number of them may
     * share a tournament.
     *
     * @param int|null $tournamentId required for scorer and tournament_admin
     * @return array{user_id:int,username:string,password:string}
     */
    public function createStaffAccount(
        string $name,
        string $username,
        string $email,
        string $role,
        ?string $password = null,
        ?int $tournamentId = null,
    ): array {
        if (!in_array($role, ['scorer', 'tournament_admin', 'admin', 'viewer'], true)) {
            throw new AccountException(
                AccountException::VALIDATION,
                'Staff accounts may be scorer, tournament administrator, admin or viewer.'
            );
        }

        if (in_array($role, self::TOURNAMENT_SCOPED_ROLES, true)) {
            if ($tournamentId === null || $tournamentId <= 0) {
                throw new AccountException(
                    AccountException::VALIDATION,
                    $role === 'scorer'
                        ? 'Choose which tournament this scorer will score.'
                        : 'Choose which tournament this administrator will run.'
                );
            }

            if ((int) Database::scalar(
                'SELECT COUNT(*) FROM tournaments WHERE id = :t',
                [':t' => $tournamentId]
            ) === 0) {
                throw new AccountException(AccountException::NOT_FOUND, 'No such tournament.', [], 404);
            }
        } else {
            // An administrator is scoped to nothing; a viewer to nothing.
            // Silently dropping a tournament here rather than refusing,
            // because the form always posts the box whatever role is picked.
            $tournamentId = null;
        }

        $name     = $this->text($name, 'Full name', 2, 120);
        $email    = $this->email($email);
        $username = $this->username($username);

        $this->assertEmailFree($email);
        $this->assertUsernameFree($username);

        $password ??= $this->readablePassword();
        $this->assertPasswordStrong($password);

        Database::run(
            'INSERT INTO users (username, name, email, password_hash, role, status, must_change_password, tournament_id)
             VALUES (:username, :name, :email, :hash, :role, :status, 1, :tournament)',
            [':username' => $username, ':name' => $name, ':email' => $email,
             ':hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
             ':role' => $role, ':status' => 'approved', ':tournament' => $tournamentId]
        );

        $newId = Database::lastInsertId();

        // The password is deliberately NOT in the log. It is handed over
        // once, in person or down a phone, and a log an organiser can read
        // is a log an organiser could paste.
        ActivityLog::record(
            'account.create_staff',
            'account',
            $newId,
            $name,
            ['role' => ['from' => null, 'to' => $role],
             'tournament_id' => ['from' => null, 'to' => $tournamentId]],
            $tournamentId,
            $username
        );

        return ['user_id' => $newId, 'username' => $username, 'password' => $password];
    }

    /**
     * Move a scorer or tournament administrator to a different tournament,
     * or clear it.
     *
     * Kept separate from the general edit so the role and the tournament
     * cannot drift apart: a scorer without one can score nothing, and the
     * scoring pad says exactly that rather than showing an empty list.
     */
    public function setStaffTournament(int $userId, ?int $tournamentId): void
    {
        $user = $this->findUser($userId);

        if (!in_array($user['role'], self::TOURNAMENT_SCOPED_ROLES, true)) {
            throw new AccountException(
                AccountException::VALIDATION,
                'Only a scorer or a tournament administrator belongs to a tournament.'
            );
        }

        if ($tournamentId !== null && (int) Database::scalar(
            'SELECT COUNT(*) FROM tournaments WHERE id = :t',
            [':t' => $tournamentId]
        ) === 0) {
            throw new AccountException(AccountException::NOT_FOUND, 'No such tournament.', [], 404);
        }

        Database::exec(
            'UPDATE users SET tournament_id = :t WHERE id = :id',
            [':t' => $tournamentId, ':id' => $userId]
        );

        // Worth a line of its own: this is what decides whether somebody can
        // score or administer at all, and "why can the scorer not save?" is
        // a question the log should be able to answer.
        ActivityLog::record(
            'account.set_tournament',
            'account',
            $userId,
            (string) $user['name'],
            ['tournament_id' => [
                'from' => $user['tournament_id'] ?? null,
                'to'   => $tournamentId,
            ]],
            $tournamentId
        );
    }

    // -----------------------------------------------------------------
    //  Passwords
    // -----------------------------------------------------------------

    /** Anyone changes their own password, proving they know the current one. */
    public function changeOwnPassword(int $userId, string $current, string $new, string $confirm): void
    {
        $user = $this->findUser($userId);

        if (!password_verify($current, (string) $user['password_hash'])) {
            throw new AccountException(AccountException::WRONG_PASSWORD, 'Your current password is not correct.');
        }

        if ($new !== $confirm) {
            throw new AccountException(AccountException::VALIDATION, 'The two new passwords do not match.');
        }

        $this->assertPasswordStrong($new);

        if (password_verify($new, (string) $user['password_hash'])) {
            throw new AccountException(AccountException::VALIDATION, 'The new password must be different from the old one.');
        }

        Database::exec(
            'UPDATE users SET password_hash = :hash, must_change_password = 0 WHERE id = :id',
            [':hash' => password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), ':id' => $userId]
        );
    }

    /**
     * An administrator resets somebody's password and reads out the new one.
     * The account must change it at next sign-in.
     */
    public function adminResetPassword(int $userId, ?string $password = null): string
    {
        $user = $this->findUser($userId);

        $password ??= $this->readablePassword();
        $this->assertPasswordStrong($password);

        Database::exec(
            'UPDATE users SET password_hash = :hash, must_change_password = 1 WHERE id = :id',
            [':hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), ':id' => $userId]
        );

        // That a reset happened, never what it was reset to.
        ActivityLog::record(
            'account.reset_password',
            'account',
            $userId,
            (string) $user['name'],
            [],
            null,
            'A new password was issued and must be changed at next sign-in.'
        );

        return $password;
    }

    // -----------------------------------------------------------------
    //  Reads
    // -----------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function pendingRegistrations(): array
    {
        return Database::all(
            "SELECT id, username, name, email, phone, address, photo_path, player_type, created_at
               FROM users WHERE status = 'pending' AND role = 'player' ORDER BY created_at"
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function listUsers(?string $role = null): array
    {
        $sql = 'SELECT id, username, name, email, phone, address, photo_path, player_type,
                       role, status, team_id, created_at
                  FROM users';
        $params = [];

        if ($role !== null) {
            $sql .= ' WHERE role = :role';
            $params[':role'] = $role;
        }

        return Database::all($sql . ' ORDER BY role, name', $params);
    }

    /** @return array<string,mixed> */
    public function findUser(int $userId): array
    {
        $user = Database::one('SELECT * FROM users WHERE id = :id', [':id' => $userId]);

        if ($user === null) {
            throw new AccountException(AccountException::NOT_FOUND, 'No such account.', [], 404);
        }

        return $user;
    }

    // -----------------------------------------------------------------
    //  Validation helpers
    // -----------------------------------------------------------------

    private function text(mixed $value, string $label, int $min, int $max): string
    {
        $value = trim((string) $value);

        if (mb_strlen($value) < $min) {
            throw new AccountException(AccountException::VALIDATION, "{$label} is required.");
        }

        if (mb_strlen($value) > $max) {
            throw new AccountException(AccountException::VALIDATION, "{$label} is too long (max {$max}).");
        }

        return $value;
    }

    private function email(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false || mb_strlen($value) > 190) {
            throw new AccountException(AccountException::VALIDATION, 'That does not look like an email address.');
        }

        return $value;
    }

    /** Letters, digits, dot, underscore and hyphen. No spaces, no ambiguity. */
    private function username(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $value)) {
            throw new AccountException(
                AccountException::VALIDATION,
                'A username is 3 to 40 characters: letters, numbers, dot, underscore or hyphen.'
            );
        }

        return $value;
    }

    private function phone(mixed $value): string
    {
        $value = trim((string) $value);
        $bare  = preg_replace('/[^0-9]/', '', $value) ?? '';

        if (strlen($bare) < 7 || strlen($bare) > 15) {
            throw new AccountException(
                AccountException::VALIDATION,
                'Enter a mobile number of 7 to 15 digits.'
            );
        }

        return mb_substr($value, 0, 20);
    }

    private function playerType(mixed $value, bool $allowEmpty = false): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            if ($allowEmpty) {
                return null;
            }

            throw new AccountException(AccountException::VALIDATION, 'Choose what kind of player you are.');
        }

        if (!array_key_exists($value, self::PLAYER_KINDS)) {
            throw new AccountException(AccountException::VALIDATION, 'That is not a kind of player.');
        }

        return $value;
    }

    private function assertPasswordStrong(string $password): void
    {
        if (strlen($password) < 8) {
            throw new AccountException(AccountException::WEAK_PASSWORD, 'Use at least 8 characters.');
        }

        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            throw new AccountException(
                AccountException::WEAK_PASSWORD,
                'Use at least one letter and one number.'
            );
        }
    }

    private function assertEmailFree(string $email): void
    {
        if ((int) Database::scalar('SELECT COUNT(*) FROM users WHERE email = :e', [':e' => $email]) > 0) {
            throw new AccountException(AccountException::EMAIL_TAKEN, 'That email address is already registered.');
        }
    }

    private function assertUsernameFree(string $username): void
    {
        if ((int) Database::scalar('SELECT COUNT(*) FROM users WHERE username = :u', [':u' => $username]) > 0) {
            throw new AccountException(AccountException::USERNAME_TAKEN, 'That username is taken.');
        }
    }

    // -----------------------------------------------------------------
    //  Photo upload
    // -----------------------------------------------------------------

    /**
     * Store an uploaded photo and return its path relative to public/.
     *
     * The file is accepted on what it *is*, not what it is called: the image
     * type is read from the bytes, and the name we save under is generated
     * here. An uploaded name is never used, so ".php" cannot be smuggled in.
     *
     * @param array<string,mixed> $file a $_FILES entry
     */
    public function storePhoto(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new AccountException(AccountException::UPLOAD_FAILED, 'The photo did not upload. Try again.');
        }

        if (($file['size'] ?? 0) > self::MAX_PHOTO_BYTES) {
            throw new AccountException(AccountException::UPLOAD_FAILED, 'The photo must be under 3 MB.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');

        if (!is_uploaded_file($tmp)) {
            throw new AccountException(AccountException::UPLOAD_FAILED, 'That was not an uploaded file.');
        }

        $info = @getimagesize($tmp);
        $ext  = match ($info['mime'] ?? '') {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => null,
        };

        if ($ext === null) {
            throw new AccountException(AccountException::UPLOAD_FAILED, 'The photo must be a JPEG, PNG or WebP image.');
        }

        $dir = BASE_PATH . '/public/' . self::PHOTO_DIR;

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new AccountException(AccountException::UPLOAD_FAILED, 'The photo folder is not writable.');
        }

        $name = bin2hex(random_bytes(16)) . '.' . $ext;

        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            throw new AccountException(AccountException::UPLOAD_FAILED, 'The photo could not be saved.');
        }

        @chmod($dir . '/' . $name, 0644);

        return self::PHOTO_DIR . '/' . $name;
    }

    // -----------------------------------------------------------------
    //  Generated secrets
    // -----------------------------------------------------------------

    /**
     * A code with no character anyone can misread aloud or mistype.
     *
     * Excludes 0/O/o, 1/I/l/i — the pairs that get confused when a code is
     * read out in a room or written on a whiteboard. Uppercase only, so the
     * lowercase look-alikes cannot appear at all.
     */
    public static function generateCode(int $length = 8): string
    {
        $alphabet = self::CODE_ALPHABET;
        $max      = strlen($alphabet) - 1;
        $code     = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * A password that can be read down a phone line without confusion.
     *
     * It must also satisfy assertPasswordStrong(), which wants at least one
     * letter and at least one digit. Drawing eight characters at random from
     * an alphabet that is 23 letters and 8 digits produces an all-letter
     * password about nine times in a hundred — so roughly one scorer account
     * in eleven used to fail to be created, with the administrator told
     * "Use at least one letter and one number" about a password they never
     * chose. Planting one of each removes the possibility rather than
     * retrying until the dice cooperate.
     */
    public function readablePassword(): string
    {
        $letters = 'ABCDEFGHJKMNPQRSTUVWXYZ';
        $digits  = '23456789';

        $chars = str_split(self::generateCode(4) . self::generateCode(4));

        // Two distinct positions, so neither planted character overwrites
        // the other.
        $forLetter = random_int(0, 3);
        $forDigit  = random_int(4, 7);

        $chars[$forLetter] = $letters[random_int(0, strlen($letters) - 1)];
        $chars[$forDigit]  = $digits[random_int(0, strlen($digits) - 1)];

        return implode('', array_slice($chars, 0, 4)) . '-' . implode('', array_slice($chars, 4));
    }
}
