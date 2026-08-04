<?php

declare(strict_types=1);

namespace Audio;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Everything the game says about sound goes through here: start it, feed it
 * once a frame, ask for an effect, mute it, close it.
 *
 * The board owns no thread and no timer. It is fed from the game loop and
 * keeps a fifth of a second of audio ahead of the speaker, which is the whole
 * trick: PHP cannot be called back from the audio thread, so the audio has to
 * be pushed rather than pulled.
 *
 * Nothing here belongs to the simulation. The world is serialized into
 * snapshots, and an FFI handle cannot be — which is also why sound effects
 * are triggered by the runner watching the world from outside rather than by
 * the domain announcing them.
 */
final class SoundBoard
{
    /**
     * Low by modern standards and entirely on purpose: it is the rate the
     * hardware being imitated ran at, and it keeps the eight second loop
     * under two hundred kilobytes.
     */
    public const RATE = 22050;

    /**
     * How much audio is kept ahead of the speaker.
     *
     * This is the one figure with two sides. Too little and a slow frame —
     * batching a thousand ticks at x1000 — drains the queue and the music
     * gaps. Too much and an effect asked for now is heard that much later,
     * which is felt immediately on a key press. A fifth of a second survives
     * three missed frames and stays under the threshold where a blip stops
     * feeling like a response to the key.
     */
    private const BUFFER_SECONDS = 0.2;

    /**
     * Effects allowed to sound at once.
     *
     * The music leaves room for exactly one effect before the sum clips, so
     * anything stacked on top of that saturates. This cap does not prevent
     * that, it bounds it — and bounds the per sample mixing loop, which is
     * the only part of the audio that is not a plain string copy.
     */
    private const MAX_CONCURRENT = 4;

    private bool $ready = false;

    private bool $muted = false;

    private string $music = '';

    /** Where the loop is being read from, in samples. */
    private int $cursor = 0;

    /** @var array<string, string> */
    private array $effects = [];

    /** @var list<array{pcm: string, offset: int}> */
    private array $playing = [];

    public function __construct(
        private readonly AudioOutputInterface $output,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Take the device and synthesize everything that will ever be played.
     *
     * Generation happens once, here, and never inside the loop: the theme is
     * a couple of hundred thousand samples and computing it per frame would
     * cost more than the simulation it plays under.
     */
    public function start(): void
    {
        if (!$this->output->open(self::RATE)) {
            $this->log('[AUDIO] aucune sortie disponible, le jeu tourne en silence');

            return;
        }

        $synth = new Synth(self::RATE);
        $this->music = (new Tune($synth))->render();

        foreach (SoundEffect::cases() as $effect) {
            $this->effects[$effect->value] = $effect->render($synth);
        }

        $this->ready = true;
        $this->log(sprintf(
            '[AUDIO] %d Hz, boucle de %.1f s, %d effets',
            self::RATE,
            strlen($this->music) / self::RATE,
            count($this->effects),
        ));
    }

    /**
     * Top the queue back up to the target lead. Called once per loop
     * iteration; does nothing at all when the speaker is still busy, which is
     * the common case.
     */
    public function tick(): void
    {
        if (!$this->ready || $this->muted || '' === $this->music) {
            return;
        }

        $missing = (int) (self::RATE * self::BUFFER_SECONDS) - $this->output->queuedBytes();

        if ($missing <= 0) {
            return;
        }

        $chunk = $this->readMusic($missing);

        // The mixing loop runs per sample, so it is kept for the rare frames
        // where an effect is actually sounding; the rest of the time the
        // music is handed over as a plain slice.
        if ([] !== $this->playing) {
            $chunk = $this->blend($chunk);
        }

        $this->output->queue($chunk);
    }

    public function play(SoundEffect $effect): void
    {
        if (!$this->ready || $this->muted || count($this->playing) >= self::MAX_CONCURRENT) {
            return;
        }

        $this->playing[] = ['pcm' => $this->effects[$effect->value], 'offset' => 0];
    }

    /**
     * Muting stops feeding the device rather than queueing silence, so what
     * is already buffered plays out and the loop then simply starves. The
     * cursor stays where it is and the music resumes from there.
     */
    public function toggleMute(): bool
    {
        $this->muted = !$this->muted;

        if ($this->muted) {
            $this->playing = [];
        }

        return $this->muted;
    }

    public function isMuted(): bool
    {
        return $this->muted;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function close(): void
    {
        $this->output->close();
        $this->ready = false;
    }

    /**
     * Read $length samples from the loop, wrapping at the end. The wrap has
     * no gap in it: the tail of the loop and its head land in the same chunk.
     */
    private function readMusic(int $length): string
    {
        $pcm = '';
        $total = strlen($this->music);

        while (strlen($pcm) < $length) {
            $slice = substr($this->music, $this->cursor, $length - strlen($pcm));
            $pcm .= $slice;
            $this->cursor += strlen($slice);

            if ($this->cursor >= $total) {
                $this->cursor = 0;
            }
        }

        return $pcm;
    }

    private function blend(string $chunk): string
    {
        foreach ($this->playing as $index => $effect) {
            $remaining = substr($effect['pcm'], $effect['offset']);
            $chunk = Mixer::add($chunk, $remaining);

            $this->playing[$index]['offset'] += min(strlen($remaining), strlen($chunk));

            if ($this->playing[$index]['offset'] >= strlen($effect['pcm'])) {
                unset($this->playing[$index]);
            }
        }

        $this->playing = array_values($this->playing);

        return $chunk;
    }

    private function log(string $message): void
    {
        $this->logger?->log(LogLevel::INFO, $message);
    }
}
