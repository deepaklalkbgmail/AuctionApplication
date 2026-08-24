/**
 * The five role guides, as content.
 *
 * Kept apart from the renderer (docs/build-guides.mjs) so the wording can
 * be edited without touching the layout, and so a section can move between
 * guides by moving one block.
 *
 * Small helpers below (callout, figure, steps, table…) exist so the prose
 * stays readable here rather than drowning in markup.
 */

export const COMPANY  = 'Deam Software Solutios';
export const PRODUCT  = 'CricAuction';
export const DEPLOYED = 'APL';

// ---------------------------------------------------------------- helpers

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/** A boxed aside. tone: note | warn | tip | stop */
const callout = (tone, title, body) => `
<div class="callout ${tone}">
  <p class="callout-title">${title}</p>
  <div class="callout-body">${body}</div>
</div>`;

/** A screenshot with a caption. `name` matches a file in docs/screens/. */
const figure = (name, caption, { width = '100%', crop = false } = {}) => `
<figure class="shot${crop ? ' crop' : ''}" data-screen="${name}" style="--w:${width}">
  <img src="SCREEN:${name}" alt="${esc(caption)}">
  <figcaption>${caption}</figcaption>
</figure>`;

/** Numbered steps. */
const steps = (items) => `<ol class="steps">${items.map((i) => `<li>${i}</li>`).join('')}</ol>`;

/** A table. head is an array; rows an array of arrays. */
const table = (head, rows, cls = '') => `
<table class="${cls}">
  <thead><tr>${head.map((h) => `<th>${h}</th>`).join('')}</tr></thead>
  <tbody>${rows.map((r) => `<tr>${r.map((c) => `<td>${c}</td>`).join('')}</tr>`).join('')}</tbody>
</table>`;

/** Key/value facts, two columns. */
const facts = (rows) => `
<dl class="facts">${rows.map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('')}</dl>`;

const kbd = (s) => `<span class="kbd">${esc(s)}</span>`;
const code = (s) => `<code>${esc(s)}</code>`;

// ------------------------------------------------------------ shared bits

const passwordSection = (who) => ({
    title: 'Your password',
    body: `
<p>Choose <strong>Password</strong> in the bar at the top of any screen you are signed
in to. You will need your current password, and the new one needs at least eight
characters with a letter and a number in it. It cannot be the same as the old one.</p>

${figure('password', 'The change-password screen, reached from the top navigation.')}

${callout('note', 'If you are sent here the moment you sign in',
`That means the password you used was issued by an administrator${who === 'scorer'
    ? ' — which is exactly how a scorer account starts life' : ''}. It is temporary.
No other screen will open until you replace it with one only you know. Do that once and
you will not see this again.`)}

<p><strong>Forgotten it?</strong> There is no reset-by-email. Ask an administrator: they
can issue a new password and read it out to you, and you will be asked to change it
straight away.</p>`,
});

const glossary = (rows) => ({
    title: 'Words you will see',
    body: table(['Term', 'What it means'], rows, 'glossary'),
});

const helpSection = {
    title: 'Getting help',
    body: `
<p>Two different kinds of problem, and they go to different people.</p>

${table(['Kind of problem', 'Who to ask'], [
    ['Your account, your details, approvals, tournament codes, deadlines',
     'Your tournament administrator — they can change all of it'],
    ['The application itself: a page that will not load, a wrong number, something broken',
     `${COMPANY}, through your administrator`],
])}

<p class="muted">When reporting a fault, say what you were doing, what you expected, and
what happened instead. A photograph of the screen is worth a paragraph.</p>`,
};

// =====================================================================
//  PLAYER
// =====================================================================

const player = {
    id: 'player',
    role: 'Player',
    title: 'Player Guide',
    tagline: 'Register, join a tournament, and go into the auction.',
    accent: '#2aa05c',
    audience: `Anyone who wants to be picked in a ${PRODUCT} auction. No technical
knowledge is assumed — if you can use a website, you can use this.`,
    sections: [
        {
            title: 'What this does, for you',
            body: `
<p>${PRODUCT} runs the player auction for a cricket tournament and then the scoring of
the matches. Your part is the beginning of it:</p>

${steps([
    'You <strong>register</strong> — your name, contact details, a photo and what kind of cricketer you are.',
    'An <strong>administrator approves</strong> your account. This confirms you are a real person.',
    'You <strong>join a tournament</strong> using a secret code the organisers give you.',
    'The administrator <strong>approves that application</strong>, and your name goes into the auction list.',
    'On auction day, team owners bid for you.',
])}

<p>Everything happens in a web browser on your phone or a computer. There is nothing to
install and nothing to pay for.</p>

${callout('note', 'Two approvals, not one',
`The first says <em>you are a real person</em>. The second says <em>you are in this
particular tournament</em>. Only the second one puts your name in front of the bidders.
It is normal to wait between the two.`)}`,
        },
        {
            title: 'Registering',
            body: `
<p>Open the application and choose <strong>Register as a player</strong>. You will be
asked for:</p>

${facts([
    ['Full name', 'As you want it read out at the auction'],
    ['Email address', 'One you will keep — it is how the organisers reach you'],
    ['Username', 'What you sign in with. 3 to 40 characters: letters, numbers, dot, underscore or hyphen. No spaces'],
    ['Mobile number', 'Between 7 and 15 digits'],
    ['Address', 'Where you live'],
    ['Kind of player', 'Batsman, bowler, all-rounder or wicket-keeper'],
    ['Password', 'At least 8 characters, with at least one letter and one number'],
    ['Photo', 'Optional. JPEG, PNG or WebP, up to 3&nbsp;MB. You can add it later'],
])}

${figure('register-form', 'The registration form. The two amber notes mark the fields that cannot be changed afterwards.')}

${callout('stop', 'Your name and email address are permanent',
`Once you register, <strong>you cannot change your full name or your email address.</strong>
Only an administrator can. They are what the administrator approves and what is printed on
the auction sheet, so letting them change afterwards would mean an approved player could
quietly become somebody else.
<br><br>
Your mobile number, address, photo and kind of player are <strong>yours to change</strong>
at any time.`)}

<h3>The check screen</h3>

<p>Pressing <strong>Continue</strong> does not create anything yet. It shows you what you
typed, with the two permanent fields marked. Read them.</p>

${figure('register-confirm', 'Step two. Nothing has been saved at this point — Go back and edit costs you nothing.')}

<p>If the name or the email is wrong, choose <strong>Go back and edit</strong>. If they
are right, press <strong>Confirm and register</strong>.</p>

<p>You will see <strong>Registration received</strong>. You cannot sign in yet.</p>`,
        },
        {
            title: 'Waiting to be approved',
            body: `
<p>An administrator has to approve your account before you can do anything. There is
nothing you need to do while you wait, and no email will arrive — the organisers will
usually tell you by whatever group you are already in.</p>

<p>If you try to sign in too early you will see:</p>

<blockquote>Your registration is still waiting for an administrator to approve it.</blockquote>

<p>That message means your password was <em>correct</em>. Nothing is wrong. If it is still
saying that after a day or two, ask the organisers.</p>`,
        },
        {
            title: 'Signing in',
            body: `
${steps([
    'Go to the application address the organisers gave you.',
    'Choose <strong>Sign in</strong>.',
    'Enter <strong>your username or your email address</strong> — either one works — and your password.',
])}

${figure('login', 'Signing in. The first box takes a username or an email address.', { width: '78%' })}

${callout('note', '“Those credentials do not match our records”',
`Shown for both an unknown username and a wrong password, deliberately — so nobody can
use the sign-in page to find out who has an account. Check for a stray space, and
remember your username is all lower case.`)}`,
        },
        {
            title: 'Joining a tournament',
            body: `
<p>Once your account is approved, sign in and choose <strong>Join a tournament</strong>.</p>

<p>The organisers will give you a <strong>secret code</strong> — eight characters, usually
sent in a group message or read out at a meeting. Type it in and press
<strong>Apply</strong>.</p>

${figure('player-apply', 'Joining with the code. Case and spacing do not matter.')}

${callout('tip', 'Codes never contain confusable characters',
`A code will never contain a zero or the letter O, and never a one, an I or a lower-case
L. Those are the characters people mistake for each other when a code is read aloud. So if
you think you are looking at a zero, it is the letter O — and if you think it is the letter
O, look again, because it is neither.
<br><br>
Upper or lower case makes no difference, and spaces and hyphens are ignored:
${code('kxq7 rbtm')} and ${code('KXQ7RBTM')} are the same code.`)}

${callout('warn', 'Applying is not the same as being in the auction',
`Your application goes into a queue. An administrator reviews it, and <strong>your name
enters the auction list when they approve it</strong> — not before. You do not need to do
anything else in the meantime.`)}

<h3>Where your application stands</h3>

<p><strong>My details</strong> lists every tournament you have applied to and what has
happened to each one:</p>

${table(['Status', 'What it means'], [
    ['<span class="pill pill-amber">Pending</span>', 'Filed. An administrator has not decided yet'],
    ['<span class="pill pill-green">Approved</span>', 'You are in the auction list for that tournament'],
    ['<span class="pill pill-grey">Rejected</span>', 'Not accepted. You may apply again — a reason may be shown'],
])}

<p>Some reasons an application is refused outright:</p>

${table(['Message', 'What happened'], [
    ['That code does not match any tournament', 'A typo, or the organisers have issued a new code. Ask them'],
    ['Registration for … is closed', 'The organisers have closed entries for now'],
    ['The auction for … was on …', 'The auction has already been held. Entries closed at the end of auction day'],
    ['You have already applied', 'Your application is already in the queue — nothing more to do'],
])}`,
        },
        {
            title: 'Keeping your details current',
            body: `
<p><strong>My details</strong> is where you keep your information up to date. This is how
the organisers reach you, so it is worth being accurate.</p>

${figure('player-profile', 'My details. The greyed-out boxes at the top are the permanent ones.')}

${table(['Detail', 'Can you change it?'], [
    ['Mobile number', '<span class="yes">Yes</span> — whenever you like'],
    ['Address', '<span class="yes">Yes</span>'],
    ['Photo', '<span class="yes">Yes</span> — leave the box empty to keep the current one'],
    ['Kind of player', '<span class="yes">Yes</span>'],
    ['Full name', '<span class="no">No</span> — ask an administrator'],
    ['Email address', '<span class="no">No</span> — ask an administrator'],
    ['Username', '<span class="no">No</span> — ask an administrator'],
])}

<p>The permanent fields are shown greyed out. That is not a fault; it is the rule
described when you registered.</p>`,
        },
        {
            title: 'The auction, and after',
            body: `
<p>You do not have to do anything on auction day, and you do not bid for yourself. Team
owners bid; an administrator brings the hammer down.</p>

<p>You can watch it happen — the live auction board is open to anyone, signed in or not.
Choose <strong>Live auction</strong>.</p>

<p>Afterwards, <strong>My details</strong> shows the outcome for each tournament:</p>

${table(['What it says', 'What it means'], [
    ['In the auction pool', 'Your lot has not come up yet'],
    ['Sold to <em>&lt;team&gt;</em>', 'You have been bought. That team is now yours'],
    ['Unsold in this round', 'Nobody bid. You can be re-listed in a later round — ask the organisers'],
])}

${callout('note', 'Unsold is not the end',
`A player who goes unsold stays in the pool and the administrator can put them back into a
later round of the same auction. It happens to good players every year.`)}`,
        },
        passwordSection('player'),
        {
            title: 'If something goes wrong',
            body: table(['What you see', 'What to do'], [
                ['Sign out does not seem to work', 'Make sure you are on the current version. If it persists, close the browser tab'],
                ['“Your session expired. Please try again.”', 'The page sat open too long. Do it again — nothing was lost'],
                ['The page looks unstyled, plain text on white', 'A file did not load. Refresh; if it persists, tell the organisers'],
                ['You cannot upload a photo', 'It must be JPEG, PNG or WebP and under 3&nbsp;MB. Photos straight from some cameras are larger'],
                ['You are signed out unexpectedly', 'Your account may have been suspended. Ask the organisers'],
                ['You are sent to a “Change password” screen every time', 'An administrator reset your password. Change it and it will stop'],
            ]),
        },
        glossary([
            ['Auction list', 'The pool of players who can be bid for. You enter it when your application is approved'],
            ['Base price', 'The lowest amount anyone can buy you for. The administrator sets it'],
            ['Lot', 'One player being auctioned — your turn under the hammer'],
            ['Purse', 'The money a team has to spend'],
            ['Secret code', 'The eight characters that let you join a tournament'],
            ['Sold / Unsold', 'Whether anybody bid for you before the hammer fell'],
            ['Squad', 'The players one team has bought'],
        ]),
        helpSection,
    ],
};

// =====================================================================
//  TEAM OWNER
// =====================================================================

const owner = {
    id: 'team-owner',
    role: 'Team Owner',
    title: 'Team Owner Guide',
    tagline: 'Name your team, then buy a squad without running out of money.',
    accent: '#1d77ca',
    audience: `The one person responsible for a franchise: naming it, and bidding for
players on auction day.`,
    sections: [
        {
            title: 'What you do',
            body: `
<p>You own exactly one team. Two jobs:</p>

${steps([
    '<strong>Before the auction</strong> — name your team, give it a short name and a colour.',
    '<strong>On auction day</strong> — bid for players against the other owners, inside a fixed purse.',
])}

${callout('note', 'One team, one owner',
`A team has exactly one owner, and the application enforces it in the database rather than
just on screen. You cannot bid for another team, and nobody else can bid for yours.`)}

<p><strong>You may also be a player yourself.</strong> That is allowed but not required.
If you want to be auctioned as well, apply to the tournament with the secret code like any
other player and wait for the administrator to approve you — being an owner does not put
you in the auction automatically, and does not stop you either.</p>`,
        },
        {
            title: 'Signing in',
            body: `
<p>An administrator creates your account and your team, and tells you the credentials.
Sign in with <strong>your username or your email address</strong> and your password.</p>

${figure('login', 'The first box takes either a username or an email address.', { width: '78%' })}

<p>If you are sent straight to a <strong>Change password</strong> screen, the password you
were given was a temporary one. Replace it and you will be let through. See
<em>Your password</em> near the end of this guide.</p>`,
        },
        {
            title: 'Naming your team',
            body: `
<p>Your team appears under <strong>My team</strong>. An administrator creates it, often
with a working name like “Team 1”, and you set the real one.</p>

${figure('owner-team', 'My team. The line under the heading tells you how long you can keep changing the name.')}

${facts([
    ['Team name', 'Up to 100 characters. Must be unique within the tournament'],
    ['Short name', '2 to 6 letters or digits — the badge on the scoreboard, like MI or CSK'],
    ['Team colour', 'Used as your accent on the auction board'],
    ['Home ground', 'Optional'],
])}

<p>A name only has to be unique <em>within your tournament</em>. If a team in a different
season used it, that is not a clash.</p>`,
        },
        {
            title: 'The name change deadline',
            body: `
<p>You can rename your team as often as you like, right up to the tournament's
<strong>team name change deadline</strong>. The screen states it plainly:</p>

<blockquote>You can change the name until 10 September 2026. After that only an
administrator can.</blockquote>

${callout('tip', 'What the deadline is for',
`A team usually has to be named before anyone knows who is in it. The deadline exists so
you can settle on a name <em>after</em> the auction, with the players you actually signed —
and then it freezes, before fixtures and shirts are printed.`)}

<p>Once the deadline has passed, the name and short name are shown greyed out and the
screen says so. The colour and home ground stay editable. If the name genuinely has to
change after that, an administrator can still do it — ask.</p>

${table(['Message', 'What it means'], [
    ['Team names were locked on …', 'The deadline has passed. Ask an administrator'],
    ['Another team in this tournament is already called …', 'Pick a different name'],
    ['Another team is already using the short name …', 'Pick different initials'],
    ['The short name is 2 to 6 letters or digits', 'No spaces or punctuation — MI, CSK, BWB'],
])}`,
        },
        {
            title: 'How the auction actually runs',
            body: `
<p>The auction is <strong>called aloud in the room</strong>. An auctioneer names each
player and you bid out loud, or with a paddle, the way an auction has always worked. You do
not bid through the application, and there is no countdown on a screen to beat.</p>

<p>When the hammer falls, the administrator types the result in: this player, to your team,
for this price. Within a second or two it appears everywhere.</p>

${callout('note', 'So what is the screen for?',
`Knowing what you can afford. Your purse, what you have spent, who you have bought — and
the same for every rival, which is what tells you whether the person bidding against you
can actually follow you up.`)}

<h3>Before you raise your hand</h3>

${steps([
    'Know your remaining purse — it is on <strong>My team</strong>.',
    'Know how many places you still have to fill, and what the cheapest players left are going for.',
    'Work out your ceiling — the next section shows how.',
])}

<h3>What the administrator will be refused</h3>

<p>If a bid you win cannot actually be recorded, the room will hear about it immediately.
It happens for exactly three reasons:</p>

${table(['Refusal', 'What it means for you'], [
    ['Your team cannot pay that much', 'You bid past your purse. The bid does not stand'],
    ['Your squad is already full', 'You have reached the maximum squad size and are done buying'],
    ['Your overseas quota is full', 'You may still bid for domestic players'],
])}

${callout('warn', 'Bid within your purse',
`Nothing stops you calling out a number in the room. The application stops it being
recorded. That is an awkward moment in front of everybody, so keep an eye on your own
figure — it is on screen for exactly this reason.`)}`,
        },
        {
            title: 'Why you cannot spend your whole purse',
            body: `
<p>The application will not let you spend down to nothing. You still have to be able to
field a legal squad afterwards, so it holds money back for the places you have not filled.</p>

<div class="formula">
  most you can bid&nbsp; =&nbsp; purse remaining&nbsp; &minus;&nbsp;
  ( places still to fill&nbsp; &times;&nbsp; cheapest player left )
</div>

<p><strong>A worked example.</strong> The minimum squad is 11. You have bought 6 players
and have ₹20,00,000 left. The cheapest player still in the pool has a base price of
₹2,00,000.</p>

${facts([
    ['Places still to fill after this one', '11 &minus; (6 + 1) = <strong>4</strong>'],
    ['Money that must be kept back', '4 × ₹2,00,000 = <strong>₹8,00,000</strong>'],
    ['Most you can bid right now', '₹20,00,000 &minus; ₹8,00,000 = <strong>₹12,00,000</strong>'],
])}

<p>Bid ₹12,00,000 and it is accepted. Bid ₹12,50,000 and it is refused, and the message
tells you the ceiling and how much is reserved.</p>

${callout('tip', 'It moves in your favour as the pool shrinks',
`The reserve is recalculated on every bid. As you fill places, fewer are left to reserve
for — so your ceiling rises through the auction. Being refused early does not mean being
refused later.`)}`,
        },
        {
            title: 'Your squad and your purse',
            body: `
<p><strong>My team</strong> shows what you have spent, what is left, and everyone you have
bought with the price paid.</p>

<p>The purse board on the auction screen shows the same for every team, so you can see what
your rivals can still afford. That is deliberate — it is an auction, not a sealed bid.</p>

${callout('warn', 'A sale cannot be undone',
`When the administrator presses <strong>Sold</strong>, the player joins your squad and the
money leaves your purse in the same instant. There is no undo. If something has genuinely
gone wrong, say so immediately — correcting it means an administrator editing the database.`)}`,
        },
        {
            title: 'Advice for auction day',
            body: `
<ul class="advice">
  <li><strong>Work out your ceiling before your player is called</strong>, not while the
      room is looking at you. The arithmetic is in the previous section.</li>
  <li><strong>Keep the screen open on your phone.</strong> Your purse changes every time a
      sale is recorded, including your own.</li>
  <li><strong>Fill the cheap places early or late, but decide which.</strong> Every unfilled
      place holds money back from your big bids.</li>
  <li><strong>Watch the other purses.</strong> If a rival cannot afford to follow you, the
      bidding is over whatever they say in the room.</li>
  <li><strong>Check the price that was entered.</strong> The administrator is typing figures
      quickly. If your purse looks wrong after a lot, say so at once — it can be undone in
      seconds, and it is far harder to unpick three lots later.</li>
</ul>`,
        },
        passwordSection('owner'),
        {
            title: 'If something goes wrong',
            body: table(['What you see', 'What to do'], [
                ['“You do not own a team”', 'The administrator has not assigned one yet, or has just done it — click again'],
                ['Your purse has not changed after a sale', 'Reload the page. If it is still wrong, tell the administrator'],
                ['Your purse looks wrong', 'Tell the administrator before the next lot — a sale can be undone, and the money comes straight back'],
                ['A player you won is not in your squad', 'The sale has not been recorded yet, or was recorded against another team. Ask the administrator'],
                ['“Your session expired”', 'The page sat open too long. Sign in again'],
                ['You cannot rename your team', 'The name change deadline has passed — ask an administrator'],
                ['You are signed out unexpectedly', 'Your account may have been suspended. Ask the administrator'],
            ]),
        },
        glossary([
            ['Base price', 'The lowest a player can be bought for'],
            ['Lot', 'One player being auctioned'],
            ['Purse', 'The money your team has to spend'],
            ['Reserve', 'Purse held back so you can still complete a legal squad'],
            ['Short name', 'Your 2–6 character badge, like MI or CSK'],
            ['Squad cap', 'The most players one team may hold'],
            ['Under the hammer', 'The player currently being called'],
            ['Unsold', 'A player nobody bid for; they can be re-listed in a later round'],
        ]),
        helpSection,
    ],
};

// =====================================================================
//  SCORER
// =====================================================================

const scorer = {
    id: 'scorer',
    role: 'Scorer',
    title: 'Scorer Guide',
    tagline: 'Record every ball, one thumb, in the sun.',
    accent: '#c2870a',
    audience: `The person at the ground with a phone, recording the match as it happens.`,
    sections: [
        {
            title: 'What you do',
            body: `
<p>You record what happened to each ball. That is all — the application works out the
rest: who is on strike, when the over ends, which runs are charged to the bowler, the run
rate, the scorecard everyone else is watching.</p>

${callout('tip', 'Record, do not calculate',
`If you find yourself doing arithmetic, stop — you are doing the application's job. Press
what happened and let it work out the consequences.`)}`,
        },
        {
            title: 'Your credentials, and the first sign-in',
            body: `
<p>Scorers do not register themselves. An administrator creates your account and gives you
a username and a password, usually read out or sent to you.</p>

${steps([
    'Sign in with the username and password you were given.',
    'You will land straight on a <strong>Change password</strong> screen. This is expected.',
    'Enter the password you were given, then choose your own — at least 8 characters with a letter and a number.',
    'From then on you go straight to the scoring pad.',
])}

${figure('password', 'The first sign-in. No other screen opens until the issued password is replaced.')}

${callout('note', 'Why it insists',
`A password that was read out over a phone is known to at least two people. Forcing the
change means the one you were handed never stays in use.`)}`,
        },
        {
            title: 'Before the first ball',
            body: `
<p>Check these before the toss, not after it. Fixing them mid-over is miserable.</p>

<ul class="checks">
  <li>You can sign in, and you have changed your password.</li>
  <li>The pad shows <strong>the right match</strong> — the two team names in the header.</li>
  <li>The badge in the header says <strong>Saving</strong>, not <strong>Demo</strong>. If it
      says Demo, what you type is not being recorded.</li>
  <li>The opening batters and the opening bowler are the right ones.</li>
  <li>Your phone will not lock every thirty seconds — set the screen timeout long.</li>
  <li>You have a signal, or you know where you do.</li>
</ul>

${callout('stop', 'The Saving badge is the one that matters',
`<strong>Saving</strong> means every press is written to the database. <strong>Demo</strong>
means it is not — you are looking at a practice scorecard. If it says Demo when it should
not, you are not signed in as the scorer, or the administrator has not set the match live.
Sort it out before the first ball.`)}`,
        },
        {
            title: 'The pad',
            body: `
${figure('scorer-phone', 'The pad as it is actually used — one hand, at the boundary.', { width: '52%' })}

<p>The keys are deliberately large. The whole pad is meant to be usable with one thumb,
standing up, in sunlight, without looking too hard.</p>

${figure('scorer-pad', 'The same pad on a laptop.')}

<p>The top of the screen always shows the score, the overs bowled, the run rate, both
batters and the current bowler. It updates the instant you press a key — the page does not
reload, so you never lose your place.</p>`,
        },
        {
            title: 'Runs',
            body: `
<p>Press the number of runs the batters actually ran or hit: ${kbd('0')} ${kbd('1')}
${kbd('2')} ${kbd('3')} ${kbd('4')} ${kbd('6')}.</p>

<p>That is it. The application then:</p>

<ul>
  <li>adds the runs to the batter's score and to the team total;</li>
  <li>charges them to the bowler;</li>
  <li>counts the ball as a legal delivery;</li>
  <li><strong>swaps the strike on odd runs</strong>;</li>
  <li>ends the over after six legal deliveries and <strong>swaps the strike again</strong>.</li>
</ul>

${callout('note', 'You never tell it who is on strike',
`It works that out from the previous ball and the laws of the game. This is the single
biggest source of mistakes in a paper scorebook, and the pad simply removes it.`)}`,
        },
        {
            title: 'Extras',
            body: `
${table(['Key', 'Use it when', 'What it does'], [
    ['<strong>Wide</strong>', 'The ball was called wide', 'One run to the team, charged to the bowler, <strong>ball not counted</strong>'],
    ['<strong>No ball</strong>', 'The ball was called no ball', 'One run, charged to the bowler, <strong>ball not counted</strong>'],
    ['<strong>Bye</strong>', 'Runs taken with no contact off the bat', 'Runs to the team, <strong>not</strong> to the batter or the bowler; ball counted'],
    ['<strong>Leg bye</strong>', 'Runs off the body', 'Runs to the team, <strong>not</strong> to the batter or the bowler; ball counted'],
])}

<p>Where extras also involve runs — four byes, or two runs off a no ball — press the extra
and then the number of runs. The pad prompts you.</p>

${callout('tip', 'The distinction that matters',
`Wides and no balls do not count as one of the six. Byes and leg byes do. Get that right
and the over count looks after itself.`)}`,
        },
        {
            title: 'Wickets',
            body: `
<p>Press <strong>Wicket</strong>, then choose how the batter was out. The pad asks for
whatever that dismissal needs — the fielder for a catch or a run out, the new batter
coming in.</p>

${table(['Dismissal', 'Charged to the bowler?'], [
    ['Bowled, caught, LBW, stumped, hit wicket', 'Yes'],
    ['Run out, retired, obstructing the field, timed out', 'No'],
])}

<p>The pad brings in the next batter in the order and puts them at the right end — a new
batter takes strike unless the wicket fell to the last ball of the over, or the batters
had crossed.</p>

${callout('warn', 'A wicket off a no ball',
`Only a run out is possible. Press <strong>No ball</strong> first, then
<strong>Wicket</strong>. If you press them the other way round, use <strong>Undo</strong>
and start again.`)}`,
        },
        {
            title: 'Undo',
            body: `
<p><strong>Undo</strong> removes the last ball completely — the runs, the wicket, the
strike change, the over count. Everything goes back exactly as it was.</p>

${callout('note', 'Undo is one deep',
`It removes the most recent ball. To take back three balls, press it three times, most
recent first. There is no way to reach into the middle of an over and change one ball; if
that is needed, an administrator has to correct the record afterwards.`)}

<p>Use it the moment you notice. Undoing the last ball is trivial; undoing five while the
bowler is running in is not.</p>`,
        },
        {
            title: 'What the pad works out for you',
            body: `
<ul class="advice">
  <li><strong>Strike rotation</strong> — on odd runs, and at the end of every over.</li>
  <li><strong>The over count</strong> — six legal deliveries, wides and no balls excluded.</li>
  <li><strong>Bowler's figures</strong> — overs, maidens, runs, wickets, economy.</li>
  <li><strong>Batter's figures</strong> — runs, balls faced, fours, sixes, strike rate.</li>
  <li><strong>Run rate</strong>, and the required rate when there is a target.</li>
  <li><strong>Maidens</strong> — an over from which no runs were charged to the bowler.</li>
</ul>

<p>All of it is recalculated from the balls you recorded, every time. Nothing is kept as a
running total that could drift out of step.</p>`,
        },
        {
            title: 'What it will not let you do',
            body: `
${table(['If you try to…', 'What happens'], [
    ['Score a match that is not live', '“This match is not in progress.” Ask the administrator to set it live'],
    ['Score without being signed in as the scorer or an administrator', 'The badge shows <strong>Demo</strong> and nothing is saved'],
    ['Record a seventh legal ball in an over', 'Refused — the over has already ended'],
    ['Record more than ten wickets', 'Refused — the innings is over'],
    ['Undo when nothing has been recorded', 'Refused, harmlessly'],
])}

${callout('warn', 'Only the first innings, for now',
`Ball-by-ball scoring, the live scorecard and undo are complete for one innings. The
innings break, the second-innings target and the result are not built yet. A match can be
scored through the first innings and no further. Plan around it.`)}`,
        },
        {
            title: 'At the ground',
            body: `
<ul class="advice">
  <li><strong>Keep a paper backup of the over-by-over.</strong> Not because the application
      loses things, but because phones run out of battery.</li>
  <li><strong>One scorer per match.</strong> Two people on the same match will both press
      keys and neither will trust the total.</li>
  <li><strong>Press as it happens.</strong> Recording three balls from memory at the end of
      an over is how mistakes get in.</li>
  <li><strong>If you lose signal</strong>, stop pressing, note the balls on paper, and enter
      them when it comes back. A press that does not reach the server is not recorded.</li>
  <li><strong>Do not sign out mid-match</strong> unless you are handing over.</li>
</ul>`,
        },
        passwordSection('scorer'),
        {
            title: 'If something goes wrong',
            body: table(['What you see', 'What to do'], [
                ['Badge says <strong>Demo</strong>', 'You are not signed in as the scorer, or the match is not live. Nothing is being saved'],
                ['“No match is being scored”', 'No match is set live. Ask the administrator'],
                ['A key does nothing', 'Check the badge still says Saving, then reload the page'],
                ['The score is wrong by one ball', 'Undo the last ball and re-enter it'],
                ['The score is wrong further back', 'Note it and tell the administrator — it needs a correction to the record'],
                ['“Your session expired”', 'Sign in again. Balls already recorded are safe'],
                ['The screen keeps locking', 'Set your phone screen timeout to its longest setting'],
            ]),
        },
        glossary([
            ['Legal delivery', 'A ball that counts toward the over — not a wide or a no ball'],
            ['Extras', 'Runs not scored off the bat: wides, no balls, byes, leg byes'],
            ['Strike rotation', 'Batters swapping ends after odd runs and at the end of an over'],
            ['Maiden', 'An over from which no runs were charged to the bowler'],
            ['Economy', 'Runs a bowler concedes per over'],
            ['Strike rate', "A batter's runs per 100 balls"],
            ['CRR', 'Current run rate — runs per over so far'],
            ['RRR', 'Required run rate — runs per over still needed to win'],
        ]),
        helpSection,
    ],
};

// =====================================================================
//  VIEWER
// =====================================================================

const viewer = {
    id: 'viewer',
    role: 'Viewer',
    title: 'Viewer Guide',
    tagline: 'Follow the auction and the match. No account needed.',
    accent: '#1d77ca',
    audience: `Anyone following the tournament — players' families, club members, sponsors,
the people in the room at the auction.`,
    sections: [
        {
            title: 'You do not need an account',
            body: `
<p>The auction board and the live scorecard are open to everybody. Open the address the
organisers gave you and choose <strong>Watch the live board</strong>.</p>

${figure('landing', 'The front page. Watching needs nothing more than the address.', { width: '82%' })}

<p>Everything a viewer sees is read-only. Nothing you do can change the auction or the
score.</p>`,
        },
        {
            title: 'Watching the auction',
            body: `
${figure('viewer-auction', 'The auction board.')}

${table(['What you see', 'What it means'], [
    ['<strong>Under the hammer</strong>', 'The player being auctioned now, with their record'],
    ['<strong>Current bid</strong>', 'The standing bid and which team holds it'],
    ['<strong>Countdown</strong>', 'Time left on this lot. It restarts every time somebody bids'],
    ['<strong>Purse board</strong>', 'What every team has left to spend, and how many players they have'],
    ['<strong>Bid feed</strong>', 'The last few bids as they land'],
    ['<strong>Up next</strong>', 'Who is coming up after this player'],
])}

<p>The board refreshes by itself every few seconds. You do not need to reload it.</p>

${callout('note', 'Why the clock keeps going back up',
`Every accepted bid restarts the countdown. It stops a lot being won by whoever clicks
last, and it means the bidding ends when the room stops bidding.`)}`,
        },
        {
            title: 'Watching a match',
            body: `
${figure('viewer-scorecard', 'The live scorecard, updating as the scorer records each ball.')}

${table(['Figure', 'What it means'], [
    ['<strong>124/3</strong>', '124 runs for the loss of 3 wickets'],
    ['<strong>Overs</strong>', 'Overs bowled out of the total for the innings'],
    ['<strong>CRR</strong>', 'Current run rate — runs per over so far'],
    ['<strong>RRR</strong>', 'Required run rate — runs per over still needed, when there is a target'],
    ['<strong>Batters</strong>', 'Runs, balls faced, fours, sixes and strike rate. The star marks who is on strike'],
    ['<strong>Bowler</strong>', 'Overs, maidens, runs conceded, wickets and economy'],
    ['<strong>This over</strong>', 'The balls of the current over, in order'],
])}

<p>It updates as the scorer presses each key — usually a second or two behind the ball.</p>`,
        },
        {
            title: 'On a phone',
            body: `
<p>Both screens are built for a phone first. Nothing to install.</p>

<p>To keep it handy, add it to your home screen: in Chrome, the menu then <em>Add to Home
screen</em>; in Safari, the share button then <em>Add to Home Screen</em>. It then opens
like an app.</p>`,
        },
        {
            title: 'If something looks wrong',
            body: table(['What you see', 'What it means'], [
                ['“No auction is running”', 'No lot is open at the moment. Correct between sessions'],
                ['“No match is being scored”', 'No match is live right now'],
                ['The score has not moved in a while', 'Play may have stopped, or the scorer is between overs. Reload if it is a long gap'],
                ['The page looks unstyled', 'A file did not load. Refresh the page'],
                ['A number looks wrong', 'Tell the organisers — viewers cannot correct anything, by design'],
            ]),
        },
        glossary([
            ['Base price', 'The lowest a player can be bought for'],
            ['Lot', 'One player being auctioned'],
            ['Purse', 'The money a team has left to spend'],
            ['Unsold', 'A lot that closed with no bid; the player may be re-listed'],
            ['CRR', 'Current run rate — runs per over so far'],
            ['RRR', 'Required run rate — runs per over still needed to win'],
            ['Maiden', 'An over from which no runs were charged to the bowler'],
            ['Economy', 'Runs a bowler concedes per over'],
        ]),
        helpSection,
    ],
};

// =====================================================================
//  ADMINISTRATOR
// =====================================================================

const admin = {
    id: 'administrator',
    role: 'Administrator',
    title: 'Administrator Guide',
    tagline: 'Run the season: approvals, tournaments, teams, and the hammer.',
    accent: '#7c3aed',
    audience: `The tournament director. Everything in the application that requires a
decision is yours.`,
    sections: [
        {
            title: 'What you are responsible for',
            body: `
<p>Five things, in the order they happen:</p>

${steps([
    '<strong>Create the tournament</strong> — its four dates, and the secret code players join with.',
    '<strong>Approve registrations</strong> — confirm each person is real.',
    '<strong>Approve applications</strong> — decide who is in this tournament. This is what fills the auction list.',
    '<strong>Create the teams</strong> and name one owner for each.',
    '<strong>Run the auction</strong>, then set up matches for the scorers.',
])}

<div class="flow">
  <div class="flow-col">
    <p class="flow-head">You</p>
    <div class="flow-box">Create the tournament<span>four dates + a secret code</span></div>
    <div class="flow-box">Approve the registration<span>“this is a real person”</span></div>
    <div class="flow-box strong">Approve the application<span>creates the player and the auction lot</span></div>
    <div class="flow-box">Create teams, name owners</div>
    <div class="flow-box">Run the auction</div>
  </div>
  <div class="flow-col">
    <p class="flow-head">Them</p>
    <div class="flow-box light">Player registers<span>name, address, mobile, photo, email</span></div>
    <div class="flow-box light">Player applies with the code</div>
    <div class="flow-box light">Owner names the team<span>renameable until the deadline</span></div>
    <div class="flow-box light">Owners bid</div>
    <div class="flow-box light">Scorer records every ball</div>
  </div>
</div>

${callout('note', 'The two approvals are different, and the second is the important one',
`Approving an <em>account</em> says the person is real. Approving an <em>application</em>
puts them in the auction — it creates their player record and their auction lot in the same
instant. There is no third step, and nothing to forget on the morning of the auction.`)}`,
        },
        {
            title: 'Your hub',
            body: `
<p>Signing in lands you on the administration hub. The two panels at the top are the queues
that hold everything else up.</p>

${figure('admin-hub', 'The hub. Both queues link straight to the screen that clears them.')}`,
        },
        {
            title: 'Creating a tournament',
            body: `
<p><strong>Administration → Tournaments → Create a tournament.</strong></p>

${table(['Field', 'What it means'], [
    ['Tournament name', 'Unique within a season'],
    ['Season', 'The year'],
    ['<strong>Auction date</strong>', 'When the hammer falls. <strong>Entries close at the end of this day</strong>'],
    ['<strong>Start date</strong>', 'First ball of the season. Must be on or after the auction'],
    ['<strong>End date</strong>', 'Last day of the season'],
    ['<strong>Team name change deadline</strong>', 'The last day an owner may rename their own team'],
    ['Purse per team', 'In rupees. 5000000 = ₹50 L'],
    ['Bid increment', 'The step between bids. 50000 = ₹50,000'],
    ['Minimum squad', 'Smallest legal squad. Drives the reserve rule owners run into'],
    ['Maximum squad', 'A team at this number cannot bid again'],
    ['Overseas limit', 'Overseas players one squad may hold'],
    ['Overs per innings', '20 for T20'],
], 'tight')}

<p>The dates are checked against each other: the end cannot precede the start, the auction
cannot fall after the first ball, and the name deadline cannot outlast the season. Any of
them can be left blank while the calendar is unsettled and filled in later.</p>

${figure('admin-tournaments', 'The tournament list. The secret code is shown in full — this screen is behind your sign-in, which is what makes the code worth anything.')}

<h3>The secret code</h3>

<p>Generated for you, eight characters, shown on the tournament card. It is the only way a
player joins. Hand it out however suits you — a WhatsApp group, a poster, read out at a
meeting.</p>

${callout('tip', 'Why codes look the way they do',
`They never contain ${code('0')}, ${code('O')}, ${code('o')}, ${code('1')}, ${code('I')},
${code('l')} or ${code('i')} — the characters people mistake for one another when a code is
read aloud or written on a whiteboard. It costs nothing and removes an entire category of
support call.`)}

<p><strong>Issue a new code</strong> replaces it — for when the old one has gone further
than you intended. Applications already filed are unaffected; only new ones need the new
code.</p>

<p><strong>Close entries</strong> stops new applications without touching anything else.
Entries also close automatically at the end of auction day.</p>

${callout('note', 'Upgrading an existing tournament',
`A tournament created before this version has no code and no dates. Press <strong>Issue a
new code</strong>, then <strong>Edit</strong> to set the four dates. Two minutes.`)}`,
        },
        {
            title: 'Approving registrations',
            body: `
<p><strong>Administration → People.</strong> The <strong>Waiting</strong> tab is everyone
who has registered and not yet been decided on, with everything they submitted — name,
email, mobile, address, kind of player and photo.</p>

${figure('admin-people', 'The registration queue.')}

${table(['Action', 'What it does'], [
    ['<strong>Approve</strong>', 'They can sign in and apply to a tournament'],
    ['<strong>Reject</strong>', 'They cannot sign in. The sign-in screen tells them plainly'],
    ['<strong>Edit details</strong>', 'Correct anything, including the name and email a player cannot change'],
    ['<strong>Reset password</strong>', 'Issues a new password, shown once'],
])}

${callout('warn', 'You are the only one who can fix a name or an email',
`A player cannot change either — that is the rule they agreed to when registering, and it
is what keeps an approved identity stable. But people do mistype their own email address,
so somebody has to be able to fix it. That somebody is you.`)}

<p><strong>Account status</strong> on the edit form can be set to <em>Suspended</em> at any
time. A suspended account is turned away on its very next click, not whenever its session
happens to expire.</p>`,
        },
        {
            title: 'Letting players into a tournament',
            body: `
<p><strong>Administration → Applications.</strong> Pick the tournament along the top; the
badge is how many are waiting.</p>

${figure('admin-applications', 'The approval queue. What you set here is what the auction uses.')}

<p>Three things are set at the moment of approval, because this is the only point at which
somebody is actually looking at the player:</p>

${facts([
    ['Base price', 'The lowest anyone may bid. Defaults to ₹2,00,000'],
    ['Auction set', 'A free label used to group the pool — Marquee, Set A …'],
    ['Overseas', 'Counts against the tournament’s overseas limit'],
    ['Note', 'Kept on the record with your name and the date'],
])}

<p><strong>Approve</strong> creates the player record and their auction lot at the back of
the queue, in one step. <strong>Reject</strong> creates nothing, and the player may
apply again.</p>

${callout('note', 'A team owner can be a player too',
`If an owner applies, approve them like anyone else. They will appear in the auction and can
be bought by another team. Being an owner neither adds them to the auction nor keeps them
out.`)}`,
        },
        {
            title: 'Teams and their owners',
            body: `
<p><strong>Administration → Teams.</strong> Choose who will own the team, give it a working
name and a 2–6 character short name, and create it. The purse comes from the tournament.</p>

${figure('admin-teams', 'Teams and owners. The name here can be a placeholder.')}

${callout('tip', 'The name really can be a placeholder',
`The owner sets the real name themselves and can change it right up to the team name change
deadline. That is what the deadline is for — it lets a name be settled with the squad after
the auction, then freezes it before fixtures are printed.`)}

<p><strong>Assign</strong> hands a team to a different owner. The outgoing owner is
released in the same step, because a team may only ever have one owner — the database
enforces it, not just the screen.</p>

<p>After the deadline an owner can no longer rename their team, but you still can, from
this screen.</p>`,
        },
        {
            title: 'Creating scorer accounts',
            body: `
<p><strong>Administration → People → Create a scorer or administrator.</strong></p>

<p>Fill in a name, a username, an email and the role. Leave the password blank and one is
generated — two four-character groups, drawn from the same unmistakable alphabet as the
tournament codes, so it can be read down a phone line.</p>

${callout('stop', 'The credentials are shown once',
`They are not stored anywhere you can read them back. Write them down or send them before
you leave the screen. If you lose them, use <strong>Reset password</strong> and issue new
ones — no harm done.`)}

<p>The account must change the password at first sign-in, so what you hand over never
stays in use.</p>`,
        },
        {
            title: 'Running the auction',
            body: `
<p>The auction is <strong>called aloud in the room</strong>. An auctioneer names the
player, owners bid by voice or by paddle, and the hammer falls. The application does not
run the bidding — it is the record of it.</p>

<p><strong>Administration → Auction</strong> is your sheet. For each player you type the
price that was agreed and the team that bought them.</p>

${figure('admin-auction', 'The auctioneer’s sheet. Purses across the top, players still to call underneath.')}

<h3>The purse board</h3>

<p>Always at the top, whether or not anything is being sold. Every team's remaining money,
what they have spent, and how many they have bought. This is what you check before
accepting a bid from the floor, and it is the question the room asks between every lot.</p>

<h3>Recording a sale</h3>

${steps([
    'Find the player — the list is in lot order, and the search box takes part of a name.',
    'Choose the <strong>team</strong>. Each one shows what it has left, so you can see at a glance whether the bid is affordable.',
    'Type the <strong>price</strong> that was called.',
    'Press <strong>Sold</strong>.',
])}

<p>Everything moves in one step: the player joins that squad, the money leaves the purse,
and the purse board updates. Either all of it happens or none of it does — a player can
never be recorded as sold without the money moving.</p>

<h3>What it will not accept</h3>

${table(['Message', 'Why'], [
    ['The price cannot be below the base price of …', 'The base price you set when approving the application'],
    ['… only has ₹X left, so it cannot pay ₹Y', 'The team cannot afford it. Check the figure, or the team'],
    ['… already has a full squad of …', 'That team has reached the maximum squad size'],
    ['… has already signed … overseas players', 'The overseas quota for that team is full'],
    ['… has already been sold', 'Undo the earlier sale first'],
])}

${callout('note', 'What it deliberately does not check',
`There is no increment ladder and no countdown. A room calls whatever it calls — ₹4,60,000
against a ₹50,000 step is perfectly normal — and refusing to record it would make your
record disagree with your auction. The rules that stay are the ones the room can genuinely
get wrong: money that does not exist, and squads that are already full.`)}

<h3>Passing a player over</h3>

<p><strong>Unsold</strong> records that nobody bid. The player moves to
<strong>Passed over</strong> at the bottom of the screen, and one press puts them back in
the queue for a later round — which is how most auctions handle a quiet first pass.</p>

<h3>Undo</h3>

${callout('tip', 'Typing a price by hand means mistyping one',
`Every sale has an <strong>Undo</strong> beside it. It returns the money, takes the player
out of the squad, and puts them back in the list to be recorded again. Use it the moment
you notice — it is far easier than explaining a wrong purse three lots later.`)}

<h3>If a team runs short</h3>

<p>After a sale you may see an amber note: <em>“… now has ₹X left, which is ₹Y short of
what a full squad would cost.”</em></p>

<p>That is advice, not a refusal. The sale already happened in the room; the application
records it and tells you what it means. It is up to you and the owner what to do about
it — usually the team buys cheaply from there on.</p>`,
        },
        {
            title: 'Setting up a match',
            body: `
${callout('warn', 'This part is not on a screen yet',
`Creating a fixture, the playing elevens and the first innings is done in the database.
Everything from the first ball onward is driven from the application.`)}

<pre><code>-- 1. The fixture. toss_decision is 'bat' or 'bowl'.
INSERT INTO matches
  (tournament_id, match_number, stage, team_a_id, team_b_id, venue,
   scheduled_at, overs_per_innings, toss_winner_team_id, toss_decision,
   status, scorer_user_id)
VALUES
  (1, 1, 'league', 1, 2, 'Marine Drive Ground', NOW(), 20, 1, 'bat', 'live',
   (SELECT id FROM users WHERE username = 'your.scorer'));

-- 2. The playing eleven per side. batting_order drives "next batter in".
INSERT INTO match_squads
  (match_id, team_id, player_id, batting_order, is_playing_xi, is_captain, is_wicket_keeper)
VALUES (1, 1, 4, 1, 1, 1, 0),
       (1, 1, 8, 2, 1, 0, 0);
       -- ... eleven rows per team

-- 3. The first innings.
INSERT INTO innings (match_id, innings_number, batting_team_id, bowling_team_id, started_at)
VALUES (1, 1, 1, 2, NOW());</code></pre>

<p>The match must be <code>status = 'live'</code> and the innings must exist, or the scorer
sees a practice scorecard instead of yours — their badge will read <strong>Demo</strong>
rather than <strong>Saving</strong>.</p>`,
        },
        {
            title: 'Passwords and lockouts',
            body: `
<p><strong>Your own:</strong> <strong>Password</strong> in the top bar, on any screen.</p>

<p><strong>Somebody else's:</strong> <strong>People → Reset password</strong> on their row.
A new one is generated and shown once. Read it to them; they must change it immediately.</p>

<p><strong>The very first administrator</strong> is created by
<code>database/reset.sql</code>:</p>

${facts([
    ['Username', '<code>admin</code>'],
    ['Email', '<code>admin@example.com</code>'],
    ['Password', '<code>ChangeMe@2026</code>'],
])}

${callout('stop', 'Change it, then change the email',
`That password is published in the project source, so the account is created already marked
as needing a new one — the first sign-in cannot get past the change-password screen. Do that,
then correct the name and email under <strong>People</strong>.`)}`,
        },
        {
            title: 'Starting clean, and demonstrating',
            body: `
<p>Two files, both run from <strong>phpMyAdmin → your database → SQL</strong>. Export a
backup first; neither can be undone.</p>

<h3>A genuinely empty application</h3>

<p><code>database/reset.sql</code> deletes every player, team, user, bid, match and ball,
and leaves one administrator so you can still sign in. Afterwards every screen says so
plainly — that is correct for an empty application, not a fault.</p>

<h3>Data for a demonstration</h3>

<p><code>database/demo_apl.sql</code> loads a complete tournament: six franchises, a
60-player pool, an auction part way through with a player under the hammer, two completed
squads, and a match ready to score.</p>

<p>Run <code>reset.sql</code> first, then <code>demo_apl.sql</code>. Every demonstration
account uses the password <code>ChangeMe@2026</code>:</p>

${table(['Role', 'Username'], [
    ['Administrator', '<code>apl.admin</code>'],
    ['Scorer', '<code>apl.scorer</code>'],
    ['Viewer', '<code>apl.viewer</code>'],
    ['Team owners', '<code>apl.ct</code>, <code>apl.mr</code>, <code>apl.hc</code>, <code>apl.df</code>, <code>apl.hw</code>, <code>apl.sl</code>'],
])}

<p>The demonstration tournament's secret code is <code>BATSMAN7</code>, and its four dates
are written relative to the day you import it — so a demonstration never opens on a season
that finished last year.</p>

${callout('warn', 'Demonstration data is not for real use',
`Those passwords are published in the project source. Run <code>reset.sql</code> again
before the tournament goes live.`)}`,
        },
        {
            title: 'What the application does not do yet',
            body: `
<p>Stated plainly, so nothing is a surprise mid-tournament.</p>

<ol class="gaps">
  <li><strong>No bulk player import.</strong> Registrations, approvals, tournaments, teams
      and owners all have screens. A CSV import does not exist yet, so a club moving an
      existing pool across has each player register.</li>
  <li><strong>Fixtures are set up in the database.</strong> See <em>Setting up a match</em>.</li>
  <li><strong>Only the first innings.</strong> The innings break, the second-innings target,
      the chase and the result are not implemented.</li>
  <li><strong>No live on-screen bidding.</strong> By design — the auction is called in the
      room and recorded here. The bidding board still exists for anyone who wants owners
      to bid on screen, but the sheet is the supported way.</li>
  <li><strong>No email.</strong> Approvals, rejections and issued passwords are not emailed.
      Somebody has to tell the person; every state is visible on their own screen.</li>
  <li><strong>No password reset by email.</strong> You reset it and read out the new one.</li>
  <li><strong>Screens refresh every three seconds</strong> rather than being pushed
      instantly. Fine for a club tournament.</li>
  <li><strong>No fixtures, points table or statistics screens.</strong> The data is
      recorded; the pages that display it are not built.</li>
</ol>`,
        },
        {
            title: 'If something goes wrong',
            body: table(['What you see', 'What to do'], [
                ['A player applied but is not in the auction', 'The application has not been approved. <strong>Applications → Approve</strong> — that is the step that creates the lot'],
                ['“That code does not match any tournament”', 'Wrong code, or it was re-issued. The current one is on the tournament card'],
                ['“Entries are closed”', 'The auction date has passed, or entries were closed by hand'],
                ['An owner cannot rename their team', 'The deadline has passed. You still can, from <strong>Teams</strong>'],
                ['Somebody cannot sign in', 'Check their status under <strong>People</strong> — pending, rejected and suspended all refuse'],
                ['A scorer’s badge says <strong>Demo</strong>', 'They are not signed in as the scorer, or no match is live'],
                ['Landing page shows an old tournament', 'Previous data still loaded. Run <code>reset.sql</code>'],
                ['<code>#1701 Cannot truncate a table…</code>', 'An old copy of <code>reset.sql</code>. The current one uses DELETE and works with foreign key checks on'],
                ['<strong>503 Service temporarily unavailable</strong>', 'The database is unreachable. Check the connection settings and the error log'],
            ]),
        },
        glossary([
            ['Secret code', 'The eight characters a player types to join a tournament'],
            ['Application', 'A player asking to join a tournament. Approving one creates their auction lot'],
            ['Approved', 'Two meanings, kept separate: an approved <em>account</em> is a real person; an approved <em>application</em> is in the auction'],
            ['Name change deadline', 'The last day an owner may rename their own team'],
            ['Lot', 'One player being auctioned'],
            ['Base price', 'The lowest a player can be bought for'],
            ['Purse', 'Money a team has to spend'],
            ['Increment', 'The fixed step between bids'],
            ['Reserve', 'Purse held back so a team can still complete a legal squad'],
            ['Unsold', 'A lot that closed with no bid; the player can be re-listed'],
        ]),
        helpSection,
    ],
};

export const GUIDES = [player, owner, scorer, viewer, admin];
