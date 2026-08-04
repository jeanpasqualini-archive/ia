<?php

declare(strict_types=1);

namespace Tests\Audio;

use Audio\NullAudioOutput;
use Audio\SoundBoard;
use Audio\SoundEffect;
use Audio\Synth;
use Audio\Tune;
use PHPUnit\Framework\TestCase;

final class SoundBoardTest extends TestCase
{
    /**
     * The container path, and the one that must never throw: no library, no
     * device, no sound, and a game that does not notice.
     */
    public function testWithoutADeviceTheBoardStaysSilentAndHarmless(): void
    {
        $board = new SoundBoard(new NullAudioOutput());
        $board->start();

        self::assertFalse($board->isReady());

        // None of these has anywhere to go, and none of them may complain
        // about it.
        $board->tick();
        $board->play(SoundEffect::Eat);
        $board->toggleMute();
        $board->close();

        self::assertFalse($board->isReady());
    }

    public function testAnUnavailableOutputIsNotAnError(): void
    {
        $output = new FakeAudioOutput();
        $output->available = false;

        $board = new SoundBoard($output);
        $board->start();

        self::assertFalse($board->isReady());

        $board->tick();

        self::assertSame('', $output->written);
    }

    public function testItKeepsAShortLeadAheadOfTheSpeakerAndNoMore(): void
    {
        $output = new FakeAudioOutput();
        $board = new SoundBoard($output);
        $board->start();

        self::assertTrue($board->isReady());

        $board->tick();
        $lead = $output->queued;

        // A fifth of a second at the rate the board runs at.
        self::assertEqualsWithDelta(SoundBoard::RATE * 0.2, $lead, 2);

        // Nothing was played in between, so there is nothing to top up.
        $board->tick();

        self::assertSame($lead, $output->queued);

        $output->drain(1000);
        $board->tick();

        self::assertSame($lead, $output->queued);
    }

    /**
     * The loop has to come round without a gap and without a repeat: the
     * bytes handed to the device across many top ups must be the theme,
     * end to end, over and over.
     */
    public function testTheLoopComesRoundSeamlessly(): void
    {
        $output = new FakeAudioOutput();
        $board = new SoundBoard($output);
        $board->start();

        $music = (new Tune(new Synth(SoundBoard::RATE)))->render();
        $wanted = strlen($music) * 2;

        while (strlen($output->written) < $wanted) {
            $board->tick();
            $output->drain(PHP_INT_MAX);
        }

        self::assertSame(
            substr(str_repeat($music, 3), 0, $wanted),
            substr($output->written, 0, $wanted)
        );
    }

    public function testMutingStopsFeedingTheDevice(): void
    {
        $output = new FakeAudioOutput();
        $board = new SoundBoard($output);
        $board->start();

        self::assertTrue($board->toggleMute());
        self::assertTrue($board->isMuted());

        $output->drain(PHP_INT_MAX);
        $board->tick();

        self::assertSame('', $output->written);

        self::assertFalse($board->toggleMute());

        $board->tick();

        self::assertNotSame('', $output->written);
    }

    public function testAnEffectChangesWhatIsPlayedAndThenStops(): void
    {
        $output = new FakeAudioOutput();
        $board = new SoundBoard($output);
        $board->start();

        $music = (new Tune(new Synth(SoundBoard::RATE)))->render();
        $chunk = (int) (SoundBoard::RATE * 0.2);

        $board->play(SoundEffect::Eat);
        $board->tick();

        $withEffect = substr($output->written, 0, $chunk);

        self::assertNotSame(substr($music, 0, $chunk), $withEffect);

        // The effect is well under one chunk, so the next top up is the bare
        // music again — an effect that kept sounding would mean its cursor
        // never advanced.
        $output->drain(PHP_INT_MAX);
        $board->tick();

        $after = substr($output->written, $chunk, $chunk);

        self::assertSame(substr($music, $chunk, $chunk), $after);
    }

    public function testEffectsAskedForWhileMutedAreDropped(): void
    {
        $output = new FakeAudioOutput();
        $board = new SoundBoard($output);
        $board->start();
        $board->toggleMute();

        $board->play(SoundEffect::Eat);

        $board->toggleMute();
        $board->tick();

        $music = (new Tune(new Synth(SoundBoard::RATE)))->render();
        $chunk = (int) (SoundBoard::RATE * 0.2);

        self::assertSame(substr($music, 0, $chunk), substr($output->written, 0, $chunk));
    }

    public function testClosingReleasesTheDevice(): void
    {
        $output = new FakeAudioOutput();
        $board = new SoundBoard($output);
        $board->start();
        $board->close();

        self::assertTrue($output->closed);
        self::assertFalse($board->isReady());
    }
}
