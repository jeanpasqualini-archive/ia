<?php

declare(strict_types=1);

namespace Tests\Map;

use Map\Builder\MapBuilder;
use Map\Provider\TerrainMapProvider;
use Map\Relief;
use PHPUnit\Framework\TestCase;

final class ReliefTest extends TestCase
{
    /**
     * The claim the whole class rests on: the ground was not invented from the
     * terrain, the terrain was cut out of the ground. So the water lies low
     * and the thicket stands high — not by convention, but because that is
     * what decided which tile was which.
     *
     * Read the other way round, from the terrain type, this is the one thing
     * that could not be checked: it would be asserting a table against itself.
     */
    public function testTheTerrainAgreesWithTheGroundItWasCutFrom(): void
    {
        $provider = new TerrainMapProvider(60, 60, 11);
        $rows = $provider->getMap();
        $relief = $provider->relief();

        self::assertNotNull($relief);

        $sums = [];
        $counts = [];

        foreach ($rows as $y => $row) {
            foreach (str_split($row) as $x => $tile) {
                $sums[$tile] = ($sums[$tile] ?? 0) + $relief->heightAt($x, $y);
                $counts[$tile] = ($counts[$tile] ?? 0) + 1;
            }
        }

        $average = static fn (string $tile): float => $sums[$tile] / $counts[$tile];

        self::assertLessThan($average(MapBuilder::HERBE), $average(MapBuilder::EAU), 'les lacs ne sont pas dans les creux');
        self::assertLessThan($average(MapBuilder::ARBRE), $average(MapBuilder::HERBE), 'le bois ne domine pas la prairie');
        self::assertLessThan($average(MapBuilder::FOURRE), $average(MapBuilder::ARBRE), 'le fourre n est pas au sommet');
    }

    /**
     * Lit from the north west, where every relief map ever printed puts the
     * sun. Backwards, the hills read as holes — instantly, and with no way to
     * say why.
     */
    public function testTheGroundIsLitFromTheNorthWest(): void
    {
        $rising = Relief::fromField($this->field(static fn (int $x, int $y): float => -($x + $y)));
        $falling = Relief::fromField($this->field(static fn (int $x, int $y): float => $x + $y));

        self::assertGreaterThan(1.0, $rising->light(10, 10), 'une pente vers le nord ouest est eclairee');
        self::assertLessThan(1.0, $falling->light(10, 10), 'la pente opposee est dans l ombre');
    }

    public function testFlatGroundIsNotShadedAtAll(): void
    {
        $flat = Relief::fromField($this->field(static fn (): float => 4.0));

        // Edges included: they are clamped and not wrapped, and a wrapped read
        // would draw a cliff along all four borders out of nothing.
        foreach ([[0, 0], [10, 10], [19, 19], [0, 19]] as [$x, $y]) {
            self::assertSame(1.0, $flat->light($x, $y), sprintf('en %d;%d', $x, $y));
        }
    }

    /**
     * Measured against the map's own average slope rather than multiplied by a
     * constant, so the lighting keeps its strength whatever the noise is
     * scaled to. A fixed multiplier would have to be retuned the day an octave
     * is added to the generator.
     */
    public function testTheLightingDoesNotDependOnHowLoudTheNoiseIs(): void
    {
        $small = Relief::fromField($this->field(static fn (int $x, int $y): float => sin($x / 3) + cos($y / 4)));
        $loud = Relief::fromField($this->field(static fn (int $x, int $y): float => 500 * (sin($x / 3) + cos($y / 4))));

        for ($y = 1; $y < 19; $y++) {
            for ($x = 1; $x < 19; $x++) {
                self::assertEqualsWithDelta($small->light($x, $y), $loud->light($x, $y), 0.02);
            }
        }
    }

    public function testTheShadingIsBoundedOnBothSides(): void
    {
        $cliff = Relief::fromField($this->field(static fn (int $x): float => $x < 10 ? 0.0 : 1000.0));

        for ($y = 0; $y < 20; $y++) {
            for ($x = 0; $x < 20; $x++) {
                // Unbounded, a step like this turns a colour black on one side
                // and white on the other, and the terrain stops being legible
                // exactly where the map is most interesting.
                self::assertGreaterThanOrEqual(0.6, $cliff->light($x, $y));
                self::assertLessThanOrEqual(1.4, $cliff->light($x, $y));
            }
        }
    }

    /**
     * @return list<list<float>>
     */
    private function field(callable $shape): array
    {
        $field = [];

        for ($y = 0; $y < 20; $y++) {
            $row = [];

            for ($x = 0; $x < 20; $x++) {
                $row[] = (float) $shape($x, $y);
            }

            $field[] = $row;
        }

        return $field;
    }
}
