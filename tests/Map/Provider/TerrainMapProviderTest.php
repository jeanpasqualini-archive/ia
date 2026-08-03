<?php

declare(strict_types=1);

namespace Tests\Map\Provider;

use Map\Builder\MapBuilder;
use Map\Provider\TerrainMapProvider;
use PHPUnit\Framework\TestCase;

final class TerrainMapProviderTest extends TestCase
{
    public function testTheMapHasTheRequestedDimensions(): void
    {
        $map = (new TerrainMapProvider(12, 40, 1))->getMap();

        self::assertCount(12, $map);

        foreach ($map as $row) {
            self::assertSame(40, mb_strlen($row));
        }
    }

    public function testOnlyKnownTilesAreProduced(): void
    {
        $map = (new TerrainMapProvider(20, 60, 1))->getMap();
        $used = array_unique(str_split(implode('', $map)));

        sort($used);
        $allowed = MapBuilder::getAllowedItems();
        sort($allowed);

        self::assertSame($allowed, $used);
    }

    public function testTheSameSeedAlwaysGivesTheSameMap(): void
    {
        self::assertSame(
            (new TerrainMapProvider(15, 50, 99))->getMap(),
            (new TerrainMapProvider(15, 50, 99))->getMap()
        );
    }

    public function testDifferentSeedsGiveDifferentMaps(): void
    {
        self::assertNotSame(
            (new TerrainMapProvider(15, 50, 1))->getMap(),
            (new TerrainMapProvider(15, 50, 2))->getMap()
        );
    }

    /**
     * The whole point of cutting the noise by quantile: a map is never mostly
     * lake by accident, so the cat always has somewhere to walk and eat.
     */
    public function testTerrainSharesHoldWhateverTheSeed(): void
    {
        foreach ([1, 7, 42, 1234] as $seed) {
            $shares = $this->shares((new TerrainMapProvider(20, 60, $seed))->getMap());

            self::assertEqualsWithDelta(TerrainMapProvider::WATER_SHARE, $shares[MapBuilder::EAU], 0.02);
            self::assertEqualsWithDelta(TerrainMapProvider::FOREST_SHARE, $shares[MapBuilder::ARBRE], 0.02);
            self::assertGreaterThan(0.01, $shares[MapBuilder::FLEUR], 'il reste des fleurs a manger');
        }
    }

    /**
     * Coherence, measured rather than eyeballed: in a generated map nearly
     * every water tile touches another one, because lakes are contiguous. The
     * same tiles shuffled — identical composition, no structure — score far
     * lower. That gap is exactly what the generator adds.
     */
    public function testWaterFormsLakesInsteadOfSprinkles(): void
    {
        $map = (new TerrainMapProvider(24, 70, 7))->getMap();

        $generated = $this->clustering($map, MapBuilder::EAU);
        $shuffled = $this->clustering($this->shuffle($map), MapBuilder::EAU);

        self::assertGreaterThan(0.95, $generated, 'les lacs se tiennent');
        self::assertGreaterThan(0.25, $generated - $shuffled, 'et ce n est pas un hasard statistique');
    }

    public function testForestsAreContiguousToo(): void
    {
        $map = (new TerrainMapProvider(24, 70, 7))->getMap();

        self::assertGreaterThan(0.95, $this->clustering($map, MapBuilder::ARBRE));
    }

    /**
     * Flowers are ranked among grass cells, so they grow in meadows rather
     * than being sprinkled one by one across the map.
     */
    public function testFlowersGrowInPatches(): void
    {
        $map = (new TerrainMapProvider(24, 70, 7))->getMap();

        self::assertGreaterThan(0.8, $this->clustering($map, MapBuilder::FLEUR));
    }

    /**
     * Fraction of the tiles of $item having at least one orthogonal neighbour
     * of the same kind.
     *
     * @param list<string> $map
     */
    private function clustering(array $map, string $item): float
    {
        $grid = array_map(str_split(...), $map);
        $total = 0;
        $touching = 0;

        foreach ($grid as $y => $row) {
            foreach ($row as $x => $tile) {
                if ($tile !== $item) {
                    continue;
                }

                $total++;

                foreach ([[0, 1], [0, -1], [1, 0], [-1, 0]] as [$dx, $dy]) {
                    if (($grid[$y + $dy][$x + $dx] ?? null) === $item) {
                        $touching++;

                        break;
                    }
                }
            }
        }

        return 0 === $total ? 0.0 : $touching / $total;
    }

    /**
     * @param list<string> $map
     *
     * @return list<string>
     */
    private function shuffle(array $map): array
    {
        $tiles = str_split(implode('', $map));
        // Deterministic shuffle: sorting by a hash keeps the test stable.
        usort($tiles, static fn (string $a, string $b): int => crc32($a . '·') <=> crc32($b . '·'));
        shuffle($tiles);

        $width = mb_strlen($map[0]);

        return array_map(
            static fn (array $chunk): string => implode('', $chunk),
            array_chunk($tiles, $width)
        );
    }

    /**
     * @param list<string> $map
     *
     * @return array<string, float>
     */
    private function shares(array $map): array
    {
        $counts = array_count_values(str_split(implode('', $map)));
        $total = array_sum($counts);

        $shares = [];

        foreach (MapBuilder::getAllowedItems() as $item) {
            $shares[$item] = ($counts[$item] ?? 0) / $total;
        }

        return $shares;
    }
}
