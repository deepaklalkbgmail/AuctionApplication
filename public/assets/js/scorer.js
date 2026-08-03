/**
 * Scorer state.
 *
 * `balls` is the single source of truth — an append-only log shaped exactly
 * like a `ball_by_ball` row. Totals, both cards, the over chips and the
 * commentary are all derived from it on read, so they cannot drift apart,
 * and Undo is just a pop plus restoring the snapshot taken before the ball.
 */
function scorer(init) {
    return {
        ...init,
        balls: [],
        striker: null,
        nonStriker: null,
        bowler: null,
        needsBatter: false,
        needsBowler: false,
        modal: null,
        pendingExtra: null,
        wicket: { type: 'bowled', fielderId: '', runs: 0, who: 'striker' },
        out: [],                 // ids of dismissed batters
        toasts: [],
        _seq: 0,

        // Live mode only. The server owns strike rotation, so the client
        // sends it just the facts it alone knows: the opening pair, a new
        // batter after a wicket, and the bowler for a new over. These hold
        // that selection until the next ball carries it.
        isOpening: false,
        pendingBatter: null,
        pendingBowler: null,
        busy: false,             // one ball in flight; blocks a double-tap

        extraKeys: [
            { type: 'wide',    short: 'WD', label: 'Wide',     aria: 'Wide' },
            { type: 'no_ball', short: 'NB', label: 'No ball',  aria: 'No ball' },
            { type: 'bye',     short: 'B',  label: 'Bye',      aria: 'Bye' },
            { type: 'leg_bye', short: 'LB', label: 'Leg bye',  aria: 'Leg bye' },
        ],

        dismissals: [
            { type: 'bowled',     label: 'Bowled' },
            { type: 'caught',     label: 'Caught' },
            { type: 'lbw',        label: 'LBW' },
            { type: 'run_out',    label: 'Run out' },
            { type: 'stumped',    label: 'Stumped' },
            { type: 'hit_wicket', label: 'Hit wicket' },
        ],

        init() {
            if (this.live) {
                this.hydrate(this.initial);
                return;
            }

            this.striker    = this.batter(this.opening.striker_id);
            this.nonStriker = this.batter(this.opening.non_striker_id);
            this.bowler     = this.bowlingXi.find(p => p.id === this.opening.bowler_id);
        },

        // ------------------------------------------------------------ lookups
        batter(id)  { return this.battingXi.find(p => p.id === id); },
        me(which)   { return which === 'striker' ? this.striker : this.nonStriker; },

        get locked() { return this.needsBatter || this.needsBowler || this.busy; },

        get availableBatters() {
            const crease = [this.striker?.id, this.nonStriker?.id];
            return this.battingXi
                .filter(p => !this.out.includes(p.id) && !crease.includes(p.id))
                .sort((a, b) => a.order - b.order);
        },

        get lastOverBowlerId() {
            // Only blocks while an over is complete and the next has not started.
            const legal = this.totals.legal;
            if (legal === 0 || legal % this.ballsPerOver !== 0) return null;
            return this.balls.filter(b => b.isLegal).slice(-1)[0]?.bowlerId ?? null;
        },

        // ------------------------------------------------------------ derived
        get totals() {
            const t = { runs: 0, wickets: 0, legal: 0, wide: 0, noBall: 0, bye: 0, legBye: 0 };

            for (const b of this.balls) {
                t.runs += b.runsOffBat + b.extraRuns;
                if (b.isLegal) t.legal++;
                if (b.isWicket) t.wickets++;
                if (b.extraType === 'wide')    t.wide   += b.extraRuns;
                if (b.extraType === 'no_ball') t.noBall += b.extraRuns;
                if (b.extraType === 'bye')     t.bye    += b.extraRuns;
                if (b.extraType === 'leg_bye') t.legBye += b.extraRuns;
            }

            t.extras = t.wide + t.noBall + t.bye + t.legBye;
            return t;
        },

        get extrasBreakdown() {
            const t = this.totals;
            return [
                { label: 'Wd', value: t.wide }, { label: 'Nb', value: t.noBall },
                { label: 'B', value: t.bye },   { label: 'Lb', value: t.legBye },
                { label: 'Total', value: t.extras },
            ];
        },

        get oversText() {
            const l = this.totals.legal;
            return `${Math.floor(l / this.ballsPerOver)}.${l % this.ballsPerOver}`;
        },

        get crr() {
            const l = this.totals.legal;
            return l === 0 ? '0.00' : (this.totals.runs / (l / this.ballsPerOver)).toFixed(2);
        },

        get rrr() {
            if (!this.target) return '—';
            const ballsLeft = this.oversLimit * this.ballsPerOver - this.totals.legal;
            if (ballsLeft <= 0) return '—';
            return (((this.target - this.totals.runs) / ballsLeft) * this.ballsPerOver).toFixed(2);
        },

        /**
         * Chips for the over on screen. The last ball recorded already knows
         * which over it belongs to, so a completed over stays visible until
         * the first ball of the next one replaces it — which is what a scorer
         * expects to see between overs.
         */
        get thisOver() {
            if (this.balls.length === 0) return [];

            const current = this.balls[this.balls.length - 1].over;

            return this.balls
                .filter(b => b.over === current)
                .map(b => ({ label: b.chip, tone: b.tone }));
        },

        card(id) {
            let runs = 0, faced = 0, fours = 0, sixes = 0;

            for (const b of this.balls) {
                if (b.strikerId !== id) continue;
                runs += b.runsOffBat;
                if (b.isLegal) faced++;
                if (b.isFour) fours++;
                if (b.isSix) sixes++;
            }

            return { runs, faced, fours, sixes, sr: faced ? (runs / faced * 100).toFixed(1) : '0.0' };
        },

        bowlerCard(id) {
            let legal = 0, conceded = 0, wickets = 0;
            const overRuns = {};

            for (const b of this.balls) {
                if (b.bowlerId !== id) continue;
                if (b.isLegal) legal++;

                // Byes and leg-byes are not charged to the bowler.
                const charged = b.runsOffBat
                    + (b.extraType === 'wide' || b.extraType === 'no_ball' ? b.extraRuns : 0);
                conceded += charged;

                overRuns[b.over] = (overRuns[b.over] ?? 0) + charged;

                if (b.isWicket && ['bowled', 'caught', 'lbw', 'stumped', 'hit_wicket'].includes(b.dismissal)) {
                    wickets++;
                }
            }

            const completed = Math.floor(legal / this.ballsPerOver);
            const maidens = Object.entries(overRuns)
                .filter(([over, runs]) =>
                    runs === 0 && this.balls.filter(b => b.over === +over && b.bowlerId === id && b.isLegal).length === this.ballsPerOver
                ).length;

            return {
                overs: `${completed}.${legal % this.ballsPerOver}`,
                maidens,
                conceded,
                wickets,
                econ: legal ? (conceded / (legal / this.ballsPerOver)).toFixed(2) : '0.00',
            };
        },

        // ------------------------------------------------------------ actions
        scoreRuns(runs) {
            this.record({ runsOffBat: runs });
        },

        openExtra(type) {
            this.pendingExtra = type;
            this.modal = 'extra';
        },

        get extraTitle() {
            return { wide: 'Wide', no_ball: 'No ball', bye: 'Byes', leg_bye: 'Leg byes' }[this.pendingExtra] ?? '';
        },

        get extraHint() {
            return {
                wide:    'One run is added automatically. The ball is not counted in the over.',
                no_ball: 'One run is added automatically, plus anything hit off the bat.',
                bye:     'Counts as a legal ball; the runs are not credited to the batter.',
                leg_bye: 'Counts as a legal ball; the runs are not credited to the batter.',
            }[this.pendingExtra] ?? '';
        },

        get extraRunsLabel() {
            return this.pendingExtra === 'no_ball' ? 'Runs off the bat' : 'Additional runs run';
        },

        confirmExtra(n) {
            const type = this.pendingExtra;
            this.modal = null;
            this.pendingExtra = null;

            if (type === 'wide') {
                this.record({ extraType: 'wide', extraRuns: 1 + n });
            } else if (type === 'no_ball') {
                this.record({ extraType: 'no_ball', extraRuns: 1, runsOffBat: n });
            } else {
                this.record({ extraType: type, extraRuns: n });
            }
        },

        openWicket() {
            this.wicket = { type: 'bowled', fielderId: '', runs: 0, who: 'striker' };
            this.modal = 'wicket';
        },

        confirmWicket() {
            const w = this.wicket;
            const out = w.type === 'run_out' ? this.me(w.who) : this.striker;

            this.modal = null;
            this.record({
                runsOffBat: w.type === 'run_out' ? w.runs : 0,
                isWicket: true,
                dismissal: w.type,
                dismissedId: out.id,
                fielderId: w.fielderId === '' ? null : +w.fielderId,
            });
        },

        /**
         * Append one ball and advance the innings.
         *
         * The snapshot in `before` is what makes Undo exact: it restores who
         * was on strike, who was bowling and who was out, rather than trying
         * to reverse the rules.
         */
        record(opts = {}) {
            if (this.locked) return this.toast('Fill the gap in the middle first', 'error');

            // Live mode: the server records the ball and hands back the whole
            // scorecard. Nothing is applied locally first — a ball that the
            // database rejected must never appear to have been scored.
            if (this.live) return this.postBall(opts);

            return this.recordLocally(opts);
        },

        recordLocally({ runsOffBat = 0, extraRuns = 0, extraType = 'none', isWicket = false,
                        dismissal = null, dismissedId = null, fielderId = null } = {}) {

            const isLegal = extraType !== 'wide' && extraType !== 'no_ball';
            const over = Math.floor(this.totals.legal / this.ballsPerOver);

            const ball = {
                seq: this.balls.length + 1,
                over,
                overLabel: `${over}.${(this.balls.filter(b => b.over === over).length) + 1}`,
                strikerId: this.striker.id,
                nonStrikerId: this.nonStriker.id,
                bowlerId: this.bowler.id,
                runsOffBat, extraRuns, extraType, isLegal, isWicket, dismissal, dismissedId, fielderId,
                isFour: runsOffBat === 4 && extraType === 'none',
                isSix:  runsOffBat === 6 && extraType === 'none',
                before: {
                    strikerId: this.striker.id,
                    nonStrikerId: this.nonStriker.id,
                    bowlerId: this.bowler.id,
                    out: [...this.out],
                },
            };

            ball.chip = this.chipFor(ball);
            ball.tone = this.toneFor(ball);
            ball.text = this.commentaryFor(ball);

            this.balls.push(ball);

            // --- strike rotation -------------------------------------------
            // Batters cross on odd runs, whether they came off the bat or were
            // run as byes / extra wides.
            let ran = runsOffBat;
            if (extraType === 'bye' || extraType === 'leg_bye') ran += extraRuns;
            if (extraType === 'wide') ran += extraRuns - 1;
            if (ran % 2 === 1) this.rotate();

            // --- wicket -----------------------------------------------------
            if (isWicket) {
                this.out.push(dismissedId);

                if (dismissedId === this.striker.id)          this.striker = null;
                else if (dismissedId === this.nonStriker.id)  this.nonStriker = null;

                this.needsBatter = true;
                this.modal = 'batter';
            }

            // --- end of over -------------------------------------------------
            if (isLegal && this.totals.legal % this.ballsPerOver === 0) {
                this.rotate();
                this.needsBowler = true;
                if (!this.needsBatter) this.modal = 'bowler';
                this.toast(`End of over ${Math.floor(this.totals.legal / this.ballsPerOver)}`, 'muted');
            }
        },

        async undo() {
            if (this.live) {
                const payload = await this.post('undo', {});

                if (payload) {
                    this.hydrate(payload);
                    this.toast('Last ball removed', 'muted');
                }

                return;
            }

            const ball = this.balls.pop();
            if (!ball) return;

            this.striker    = this.batter(ball.before.strikerId);
            this.nonStriker = this.batter(ball.before.nonStrikerId);
            this.bowler     = this.bowlingXi.find(p => p.id === ball.before.bowlerId);
            this.out        = [...ball.before.out];
            this.needsBatter = false;
            this.needsBowler = false;
            this.modal = null;

            this.toast(`Undone: ${ball.chip}`, 'muted');
        },

        rotate() {
            [this.striker, this.nonStriker] = [this.nonStriker, this.striker];
        },

        swapStrike() {
            this.rotate();
            this.toast(`${this.striker.name} on strike`, 'muted');
        },

        /**
         * Fills the vacant end. At the start of an innings both ends are
         * vacant, so this is called twice; after a wicket, once.
         */
        sendInBatter(p) {
            if (this.striker === null) this.striker = p;
            else if (this.nonStriker === null) this.nonStriker = p;

            // Held until the next ball carries it to the server.
            if (this.live && !this.isOpening) this.pendingBatter = p.id;

            this.needsBatter = this.striker === null || this.nonStriker === null;
            this.modal = this.needsBatter ? 'batter' : (this.needsBowler ? 'bowler' : null);
            this.toast(`${p.name} to the crease`, 'success');
        },

        setBowler(p) {
            this.bowler = p;

            if (this.live) this.pendingBowler = p.id;

            this.needsBowler = false;
            this.modal = this.needsBatter ? 'batter' : null;
            this.toast(`${p.name} into the attack`, 'success');
        },

        // ---------------------------------------------------------------- api
        /**
         * POST one delivery. The response is the entire scorecard, so the
         * client replaces its state instead of patching it — a dropped or
         * out-of-order response can never leave the pad half-updated.
         */
        async postBall({ runsOffBat = 0, extraRuns = 0, extraType = 'none', isWicket = false,
                         dismissal = null, dismissedId = null, fielderId = null } = {}) {

            const fields = {
                innings_id:   this.inningsId,
                runs_off_bat: runsOffBat,
                extra_runs:   extraRuns,
                extra_type:   extraType,
                is_wicket:    isWicket ? '1' : '0',
            };

            if (isWicket) {
                fields.dismissal_type = dismissal;
                if (dismissedId) fields.dismissed_player_id = dismissedId;
                if (fielderId)   fields.fielder_id = fielderId;
            }

            if (this.isOpening) {
                fields.striker_id     = this.striker.id;
                fields.non_striker_id = this.nonStriker.id;
                fields.bowler_id      = this.bowler.id;
            } else {
                if (this.pendingBatter) fields.new_batter_id = this.pendingBatter;
                if (this.pendingBowler) fields.bowler_id     = this.pendingBowler;
            }

            const payload = await this.post('ball', fields);
            if (payload) this.hydrate(payload);
        },

        /** Returns the payload, or null after showing why it was refused. */
        async post(action, fields) {
            if (this.busy) return null;
            this.busy = true;

            const body = new URLSearchParams({
                action,
                csrf_token: this.csrf,
                innings_id: this.inningsId,
                ...fields,
            });

            try {
                const res = await fetch(this.apiUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': this.csrf, 'Accept': 'application/json' },
                    body,
                });

                const data = await res.json();

                if (!data.ok) {
                    this.toast(data.message || 'That ball was refused', 'error');

                    // The server can also be telling us what it still needs.
                    if (data.error === 'NEEDS_BATTER')  { this.needsBatter = true; this.modal = 'batter'; }
                    if (data.error === 'NEEDS_BOWLER')  { this.needsBowler = true; this.modal = 'bowler'; }
                    if (data.error === 'NEEDS_OPENING') { this.isOpening = true; this.needsBatter = true; }

                    return null;
                }

                return data;
            } catch (e) {
                this.toast('Could not reach the scoring server — nothing was recorded', 'error');
                return null;
            } finally {
                this.busy = false;
            }
        },

        /** Replace all local state with the server's scorecard. */
        hydrate(payload) {
            if (!payload || !payload.balls) return;

            const previous = this.balls.length;

            this.balls = payload.balls.map(b => this.fromServer(b));

            const st = payload.state || {};

            if (st.needs_opening) {
                this.isOpening  = true;
                this.striker    = null;
                this.nonStriker = null;
                this.bowler     = null;
                this.needsBatter = true;
                this.needsBowler = true;
            } else {
                this.isOpening   = false;
                this.striker     = st.striker_id ? this.batter(st.striker_id) : null;
                this.nonStriker  = st.non_striker_id ? this.batter(st.non_striker_id) : null;
                this.bowler      = st.bowler_id ? this.bowlingXi.find(p => p.id === st.bowler_id) : null;
                this.needsBatter = !!st.needs_batter;
                this.needsBowler = !!st.needs_bowler;
            }

            this.out = st.out || [];
            this.pendingBatter = null;
            this.pendingBowler = null;

            if (this.needsBatter)      this.modal = 'batter';
            else if (this.needsBowler) this.modal = 'bowler';
            else if (this.modal === 'batter' || this.modal === 'bowler') this.modal = null;

            if (payload.innings && payload.innings.target !== undefined) {
                this.target = payload.innings.target;
            }

            if (this.balls.length > previous) {
                this.toast(this.balls[this.balls.length - 1].chip === 'W'
                    ? 'Wicket recorded'
                    : `Recorded: ${this.balls[this.balls.length - 1].chip}`, 'success');
            }
        },

        /** Server row -> the shape the rest of this component already uses. */
        fromServer(b) {
            const ball = {
                seq: b.seq,
                over: b.over,
                overLabel: `${b.over}.${b.ball_in_over}`,
                strikerId: b.striker_id,
                nonStrikerId: b.non_striker_id,
                bowlerId: b.bowler_id,
                runsOffBat: b.runs_off_bat,
                extraRuns: b.extra_runs,
                extraType: b.extra_type,
                isLegal: b.is_legal,
                isFour: b.is_four,
                isSix: b.is_six,
                isWicket: b.is_wicket,
                dismissal: b.dismissal_type,
                dismissedId: b.dismissed_player_id,
                fielderId: b.fielder_id,
            };

            ball.chip = this.chipFor(ball);
            ball.tone = this.toneFor(ball);
            ball.text = this.commentaryFor(ball);

            return ball;
        },

        // -------------------------------------------------------------- labels
        chipFor(b) {
            if (b.isWicket) return 'W';
            if (b.extraType === 'wide')    return 'wd' + (b.extraRuns > 1 ? b.extraRuns - 1 : '');
            if (b.extraType === 'no_ball') return 'nb' + (b.runsOffBat > 0 ? b.runsOffBat : '');
            if (b.extraType === 'bye')     return b.extraRuns + 'b';
            if (b.extraType === 'leg_bye') return b.extraRuns + 'lb';
            return String(b.runsOffBat);
        },

        toneFor(b) {
            if (b.isWicket)                 return 'bg-rose-600 text-white';
            if (b.extraType !== 'none')     return 'bg-amber-400/20 text-amber-200';
            if (b.runsOffBat === 6)         return 'bg-violet-500/25 text-violet-200';
            if (b.runsOffBat === 4)         return 'bg-sky-500/25 text-sky-200';
            if (b.runsOffBat === 0)         return 'bg-white/8 text-slate-400';
            return 'bg-white/10 text-white';
        },

        commentaryFor(b) {
            const bat = this.batter(b.strikerId)?.name ?? '';
            const bowl = this.bowlingXi.find(p => p.id === b.bowlerId)?.name ?? '';
            const head = `${bowl} to ${bat}, `;

            if (b.isWicket) {
                const who = this.batter(b.dismissedId)?.name ?? bat;
                return `${head}OUT — ${who}, ${b.dismissal.replace('_', ' ')}`;
            }
            if (b.extraType === 'wide')    return `${head}wide${b.extraRuns > 1 ? ` +${b.extraRuns - 1}` : ''}`;
            if (b.extraType === 'no_ball') return `${head}no ball${b.runsOffBat ? `, ${b.runsOffBat} off the bat` : ''}`;
            if (b.extraType === 'bye')     return `${head}${b.extraRuns} bye${b.extraRuns === 1 ? '' : 's'}`;
            if (b.extraType === 'leg_bye') return `${head}${b.extraRuns} leg bye${b.extraRuns === 1 ? '' : 's'}`;
            if (b.runsOffBat === 0)        return `${head}no run`;
            if (b.runsOffBat === 4)        return `${head}FOUR`;
            if (b.runsOffBat === 6)        return `${head}SIX`;
            return `${head}${b.runsOffBat} run${b.runsOffBat === 1 ? '' : 's'}`;
        },

        hotkey(e) {
            if (this.modal || e.metaKey || e.ctrlKey || e.altKey) return;
            if (['INPUT', 'SELECT', 'TEXTAREA'].includes(e.target.tagName)) return;

            const k = e.key.toLowerCase();

            if (['0', '1', '2', '3', '4', '6'].includes(k)) { e.preventDefault(); this.scoreRuns(+k); }
            else if (k === 'w') { e.preventDefault(); this.openWicket(); }
            else if (k === 'd') { e.preventDefault(); this.openExtra('wide'); }
            else if (k === 'n') { e.preventDefault(); this.openExtra('no_ball'); }
            else if (k === 'u') { e.preventDefault(); this.undo(); }
        },

        toast(message, kind = 'muted') {
            const id = ++this._seq;
            this.toasts.push({ id, message, kind });
            setTimeout(() => (this.toasts = this.toasts.filter(t => t.id !== id)), 1800);
        },
    };
}
