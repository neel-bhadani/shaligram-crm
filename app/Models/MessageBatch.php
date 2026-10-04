<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One bulk send: one tag to many leads, started by one admin.
 *
 * Each lead is its own MessageLog row, with batch_id pointing here, so a
 * lead's own history still says what it was sent. This row is what the Queue
 * shows instead of a few hundred of them.
 *
 * Who started it, who stopped it and who resumed it are separate columns —
 * stopping never writes over who started.
 */
class MessageBatch extends Model
{
    protected $guarded = [];

    protected $casts = [
        'send_at' => 'datetime',
        'held_at' => 'datetime',
        'stopped_at' => 'datetime',
        'resumed_at' => 'datetime',
        'failure_streak' => 'integer',
    ];

    /** Rows still to go: waiting for their time, or held with the batch. */
    public const WAITING = ['queued', 'held'];

    public function messages()
    {
        return $this->hasMany(MessageLog::class, 'batch_id');
    }

    public function template()
    {
        return $this->belongsTo(MessageTemplate::class, 'template_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stopper()
    {
        return $this->belongsTo(User::class, 'stopped_by');
    }

    public function resumer()
    {
        return $this->belongsTo(User::class, 'resumed_by');
    }

    /**
     * How many rows are in each state, every state present.
     *
     * `unknown` is counted on its own: neither sent nor failed — it may have
     * reached the customer, and somebody has to look it up in 11za.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $byStatus = $this->messages()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($n) => (int) $n);

        return collect(['queued', 'held', 'sending', 'sent', 'opened', 'unknown', 'failed', 'skipped', 'cancelled'])
            ->mapWithKeys(fn (string $status) => [$status => $byStatus[$status] ?? 0])
            ->all();
    }

    /**
     * Hold it: nothing more is sent until somebody presses Resume. Never
     * resumes by itself.
     *
     * Every waiting row becomes `held`, and the worker only claims `queued`,
     * so a job already delayed — the next row's turn, or a retry — wakes and
     * does nothing. Only a running batch can be held; the first reason wins.
     */
    public function hold(string $reason): bool
    {
        $held = static::whereKey($this->id)->where('status', 'running')
            ->update(['status' => 'held', 'held_reason' => $reason, 'held_at' => now()]);

        if ($held) {
            $this->messages()->where('status', 'queued')->update(['status' => 'held']);
        }

        $this->refresh();

        return (bool) $held;
    }

    /**
     * running | scheduled | held | stopped | finished. Finished is worked out:
     * a running batch with nothing left waiting or being sent.
     *
     * @param  array<string, int>|null  $counts
     */
    public function state(?array $counts = null): string
    {
        if ($this->status !== 'running') {
            return $this->status;
        }

        $counts ??= $this->counts();

        if ($counts['queued'] + $counts['held'] + $counts['sending'] === 0) {
            return 'finished';
        }

        return $this->send_at && $this->send_at->isFuture() ? 'scheduled' : 'running';
    }
}
