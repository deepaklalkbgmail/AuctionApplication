/**
 * Auction screen behaviour.
 *
 * Two modes:
 *   live  — bids POST to api/auction.php and the screen polls the server for
 *           state. The server is the only authority on who is leading.
 *   local — no database or no session behind the page: bids resolve
 *           optimistically in the browser so the UI stays reviewable.
 *
 * The purse and squad checks below are a fast pre-check for the button
 * states, never the enforcement. AuctionService re-runs all of them inside
 * the transaction, and only its answer changes any money.
 */
function auctionScreen(initial) {
    return {
        ...initial,
        paused: false,
        flash: false,
        toasts: [],
        _seq: 0,

        init() {
            this.bids = this.bids.map((b, i) => ({ ...b, id: 'seed-' + i }));

            setInterval(() => {
                if (this.paused || this.secondsLeft <= 0) return;

                this.secondsLeft--;

                if (this.secondsLeft === 0) {
                    this.toast(
                        this.leader ? `Going once… ${this.leader.short} leads` : 'No bids — lot closing',
                        'muted'
                    );
                }
            }, 1000);

            // Poll for other teams' bids. Phase 4 swaps this for SSE; the
            // apply step stays the same either way.
            if (this.live) {
                setInterval(() => this.refresh(), 3000);
            }
        },

        // ---------------------------------------------------------- getters
        get myTeam() {
            return this.teams.find(t => t.id === this.myTeamId) || null;
        },

        get leader() {
            return this.teams.find(t => t.id === this.leaderId) || null;
        },

        get isLeading() {
            return this.myTeamId !== null && this.leaderId === this.myTeamId;
        },

        get sortedTeams() {
            return [...this.teams].sort((a, b) => b.remaining - a.remaining);
        },

        // ---------------------------------------------------------- helpers
        nextBid(steps = 1) {
            return this.currentBid + this.increment * steps;
        },

        /** Client-side mirror of the server's purse + squad guard. */
        canAfford(amount) {
            const team = this.myTeam;
            if (!team) return false;
            if (team.bought >= this.maxSquad) return false;

            // Hold back enough to fill every remaining slot at the league's
            // minimum base price — a team may not spend itself below a legal squad.
            const slotsAfter = this.maxSquad - team.bought - 1;
            const reserve = slotsAfter * this.minBase;

            return amount + reserve <= team.remaining;
        },

        /** 3250000 -> "₹32.5 L" — mirrors Security::money() on the PHP side. */
        fmt(amount) {
            if (amount >= 1e7) return '₹' + (amount / 1e7).toFixed(2).replace(/\.?0+$/, '') + ' Cr';
            if (amount >= 1e5) return '₹' + (amount / 1e5).toFixed(2).replace(/\.?0+$/, '') + ' L';
            return '₹' + Math.round(amount).toLocaleString('en-IN');
        },

        // ----------------------------------------------------------- actions
        async placeBid(steps = 1) {
            const amount = this.nextBid(steps);
            const team = this.myTeam;

            // Pre-checks, so an obviously doomed bid costs no round trip.
            if (!team) return this.toast('Only team owners can bid', 'error');
            if (this.isLeading) return this.toast('You already hold the highest bid', 'muted');
            if (team.bought >= this.maxSquad) return this.toast('Squad is full', 'error');
            if (!this.canAfford(amount)) {
                return this.toast(`Insufficient purse — ${this.fmt(team.remaining)} left`, 'error');
            }

            if (this.live) {
                const res = await this.post('bid', { lot_id: this.lotId, amount: amount.toFixed(2) });
                if (res) {
                    this.applyLot(res.lot);
                    this.toast(`Bid placed: ${this.fmt(amount)}`, 'success');
                    this.refresh();
                }
                return;
            }

            // Local preview mode.
            this.currentBid = amount;
            this.leaderId = team.id;
            this.bidCount++;
            this.secondsLeft = this.timer;   // anti-snipe: each bid resets the clock

            this.bids.unshift({
                id: 'local-' + (++this._seq),
                amount,
                short: team.short,
                team: team.name,
                color: team.color,
                at: new Date().toLocaleTimeString('en-GB'),
            });

            this.pulse();
            this.toast(`Bid placed: ${this.fmt(amount)}`, 'success');
        },

        async hammer(outcome) {
            if (outcome === 'sold' && !this.leader) {
                return this.toast('No bids to close', 'error');
            }

            if (this.live) {
                const res = await this.post(outcome === 'sold' ? 'sell' : 'unsold', { lot_id: this.lotId });
                if (res) {
                    this.toast(
                        outcome === 'sold'
                            ? `SOLD — ${res.player} to ${res.team} for ${this.fmt(parseFloat(res.price))}`
                            : `${res.player} goes unsold`,
                        outcome === 'sold' ? 'success' : 'muted'
                    );
                    this.paused = true;
                    this.secondsLeft = 0;
                    this.refresh();
                }
                return;
            }

            // Local preview mode.
            if (outcome === 'sold') {
                const team = this.leader;
                team.spent += this.currentBid;
                team.remaining = team.total - team.spent;
                team.bought++;

                this.toast(`SOLD — ${this.playerName} to ${team.name} for ${this.fmt(this.currentBid)}`, 'success');
            } else {
                this.toast(`${this.playerName} goes unsold`, 'muted');
            }

            this.paused = true;
            this.secondsLeft = 0;
        },

        // -------------------------------------------------------------- api
        /** POST an action. Returns the payload, or null after showing why not. */
        async post(action, fields) {
            const body = new URLSearchParams({ action, csrf_token: this.csrf, ...fields });

            try {
                const res = await fetch(this.apiUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': this.csrf, 'Accept': 'application/json' },
                    body,
                });

                const data = await res.json();

                if (!data.ok) {
                    this.toast(data.message || 'That bid was rejected', 'error');
                    this.refresh();          // our view of the lot was stale
                    return null;
                }

                return data;
            } catch (e) {
                this.toast('Could not reach the auction server', 'error');
                return null;
            }
        },

        /** Pull authoritative state; the server wins every disagreement. */
        async refresh() {
            try {
                const url = `${this.apiUrl}?action=state&tournament_id=${this.tournamentId}`;
                const res = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                const data = await res.json();

                if (!data.ok || !data.lot) return;

                this.applyLot({
                    current_bid: data.lot.current_bid,
                    bid_count: data.lot.bid_count,
                    current_bidder_team_id: data.lot.bidder_team_id,
                    ends_at: data.lot.ends_at,
                });

                this.teams = data.teams.map(t => ({
                    id: +t.id, name: t.name, short: t.short_name, color: t.primary_color,
                    total: +t.purse_total, spent: +t.purse_spent,
                    remaining: +t.purse_remaining, bought: +t.players_bought,
                }));

                this.bids = data.bids.map((b, i) => ({
                    id: 'srv-' + i,
                    amount: +b.bid_amount,
                    short: b.short_name,
                    team: b.team_name,
                    color: b.primary_color,
                    at: (b.placed_at || '').slice(11, 19),
                }));
            } catch (e) {
                /* a dropped poll is not worth interrupting the auction for */
            }
        },

        applyLot(lot) {
            if (!lot) return;

            const bid = parseFloat(lot.current_bid);
            if (!Number.isNaN(bid) && bid !== this.currentBid) this.pulse();

            if (!Number.isNaN(bid)) this.currentBid = bid;
            if (lot.bid_count !== undefined) this.bidCount = +lot.bid_count;

            this.leaderId = lot.current_bidder_team_id === null || lot.current_bidder_team_id === undefined
                ? null
                : +lot.current_bidder_team_id;

            if (lot.seconds_left !== undefined && lot.seconds_left !== null) {
                this.secondsLeft = Math.max(0, +lot.seconds_left);
            } else if (lot.ends_at) {
                // MySQL DATETIME has no zone; the server runs on UTC.
                const ends = Date.parse(lot.ends_at.replace(' ', 'T') + 'Z');
                if (!Number.isNaN(ends)) {
                    this.secondsLeft = Math.max(0, Math.round((ends - Date.now()) / 1000));
                }
            }
        },

        pulse() {
            this.flash = true;
            setTimeout(() => (this.flash = false), 220);
        },

        toast(message, kind = 'muted') {
            const id = ++this._seq;
            this.toasts.push({ id, message, kind });
            setTimeout(() => (this.toasts = this.toasts.filter(t => t.id !== id)), 3200);
        },
    };
}
