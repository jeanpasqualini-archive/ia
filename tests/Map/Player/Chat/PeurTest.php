<?php

declare(strict_types=1);

namespace Tests\Map\Player\Chat;

use Map\Builder\MapBuilder;
use Map\Player\Chat\Peur;
use PHPUnit\Framework\TestCase;

final class PeurTest extends TestCase
{
    public function testACatFearsNothingUntilSomethingHurts(): void
    {
        $peur = new Peur();

        self::assertTrue($peur->isEmpty());
        self::assertSame(0.0, $peur->expect(['sol:R']));
        self::assertSame(0, $peur->bias(MapBuilder::RONCE, 3, 3));
    }

    /**
     * What was present takes the blame, and everything present takes part of
     * it: the cat does not decide whether the bramble or the place is at
     * fault.
     */
    public function testEverythingPresentTakesPartOfTheBlame(): void
    {
        $map = new MapBuilder(['XXX', 'XRX', 'XXX']);
        $peur = new Peur();

        $peur->remember(Peur::cues($map, 1, 1), 1.0);

        $cues = array_column($peur->strongest(), 0);

        self::assertContains('sol:R', $cues, 'le terrain');
        self::assertContains('lieu:0,0', $cues, 'et le lieu');
    }

    /**
     * Rescorla and Wagner, and the reason the rule moves towards the *error*
     * rather than towards the pain: a cue that already predicts the sting
     * leaves nothing to explain, so a familiar danger in a new place teaches
     * almost nothing about the place.
     */
    public function testAKnownDangerBlocksLearningAboutWhereItHappened(): void
    {
        $peur = new Peur();

        // Stung on brambles until the terrain alone predicts it.
        for ($i = 0; $i < 30; $i++) {
            $peur->remember(['sol:R'], 1.0);
        }

        $known = $peur->expect(['sol:R']);
        self::assertGreaterThan(0.9, $known, 'le terrain predit desormais la piqure');

        // The same sting, somewhere new. There is almost no error left.
        $peur->remember(['sol:R', 'lieu:9,9'], 1.0);

        self::assertLessThan(0.1, $peur->expect(['lieu:9,9']), 'le lieu n a presque rien appris');
    }

    public function testAnUnexplainedPainIsLearntQuickly(): void
    {
        $peur = new Peur();
        $peur->remember(['sol:R', 'lieu:0,0'], 1.0);

        // Nothing predicted it, so the whole error was shared out.
        self::assertGreaterThan(0.3, $peur->expect(['sol:R']));
    }

    /**
     * Forgetting is half the behaviour: without it one scratch would keep a
     * cat off a terrain for the rest of its life, with no way to find out it
     * had changed.
     */
    public function testFearFadesAndEventuallyGoes(): void
    {
        $peur = new Peur();
        $peur->remember(['sol:R'], 1.0);

        $fresh = $peur->expect(['sol:R']);

        $peur->fade();
        self::assertLessThan($fresh, $peur->expect(['sol:R']), 'il s estompe');

        for ($i = 0; $i < 200; $i++) {
            $peur->fade();
        }

        self::assertTrue($peur->isEmpty(), 'et finit par disparaitre');
    }

    public function testFadingIsPeriodicRatherThanEveryTick(): void
    {
        $peur = new Peur();

        self::assertTrue($peur->shouldFade(0));
        self::assertFalse($peur->shouldFade(1));
        self::assertTrue($peur->shouldFade(25));
    }

    /**
     * Stung on the same ground in many different places, the terrain cue is
     * reinforced every time while each place fades on its own. The memory
     * generalises without anything ever deciding that it should.
     */
    public function testRepeatedInManyPlacesTheMemoryGeneralises(): void
    {
        $peur = new Peur();

        for ($region = 0; $region < 12; $region++) {
            $peur->remember(['sol:R', 'lieu:' . $region . ',0'], 1.0);

            for ($tick = 0; $tick < 20; $tick++) {
                $peur->fade();
            }
        }

        self::assertGreaterThan(
            $peur->expect(['lieu:0,0']),
            $peur->expect(['sol:R']),
            'le terrain a survecu a tous les lieux'
        );
    }

    /**
     * A poisoned meal is learnt in one go, where a scratch is learnt little
     * by little. The animal that needed poisoning twice usually did not get
     * the chance.
     */
    public function testSomethingSwallowedIsLearntInASingleTrial(): void
    {
        $scratched = new Peur();
        $scratched->remember(['sol:R'], 1.0);

        $poisoned = new Peur();
        $poisoned->remember(['sol:D'], 1.0, swallowed: true);

        self::assertGreaterThan(
            $scratched->expect(['sol:R']),
            $poisoned->expect(['sol:D'])
        );
    }

    /**
     * Garcia and Koelling, 1966: illness binds to what was tasted and barely
     * at all to where it happened, while an injury binds to the place as
     * readily as to the thing.
     *
     * Without it the region of a poisoned meal became as dear as the plant,
     * so the good flowers growing beside it were avoided too — measured, a
     * cat that already knew still ate six more foxgloves with a real flower
     * within reach.
     */
    public function testAPoisonBlamesThePlantAndNotThePlace(): void
    {
        $poisoned = new Peur();
        $poisoned->remember(['sol:D', 'lieu:4,4'], 3.0, swallowed: true);

        self::assertGreaterThan(
            5 * $poisoned->expect(['lieu:4,4']),
            $poisoned->expect(['sol:D']),
            'la plante porte le blame, pas le pre ou elle poussait'
        );
    }

    public function testAnInjuryBlamesTheePlaceAsReadilyAsTheGround(): void
    {
        $stung = new Peur();
        $stung->remember(['sol:R', 'lieu:4,4'], 1.0);

        self::assertEqualsWithDelta(
            $stung->expect(['sol:R']),
            $stung->expect(['lieu:4,4']),
            0.001,
            'une griffure ne choisit pas entre la ronce et l endroit'
        );
    }

    public function testFearIsPricedAsADetourRatherThanAsAWall(): void
    {
        $map = new MapBuilder(['XXX', 'XRX', 'XXX']);
        $peur = new Peur();
        $peur->remember(Peur::cues($map, 1, 1), 1.0);

        // A straight step over grass costs ten, so a learnt sting has to be
        // worth several tiles of walking — and stay finite.
        $bias = $peur->bias(MapBuilder::RONCE, 1, 1);

        self::assertGreaterThan(30, $bias);
        self::assertLessThan(1000, $bias);
    }
}
