<?php

declare(strict_types=1);

namespace Runtime;

/**
 * Live memory readings.
 *
 * Two ceilings can kill the game and they fail very differently: PHP's own
 * memory_limit raises a catchable fatal error, while the container cgroup
 * limit makes the kernel SIGKILL the process — the exit code 137 you get with
 * no stack trace and nothing in the log. Both are reported here so it is
 * visible which one is being approached.
 */
class MemoryUsage
{
    private const CGROUP_V2_CURRENT = '/sys/fs/cgroup/memory.current';
    private const CGROUP_V2_MAX = '/sys/fs/cgroup/memory.max';
    private const CGROUP_V1_CURRENT = '/sys/fs/cgroup/memory/memory.usage_in_bytes';
    private const CGROUP_V1_MAX = '/sys/fs/cgroup/memory/memory.limit_in_bytes';

    /** Above this ratio of a known limit, the reading is worth shouting about. */
    public const DANGER_RATIO = 0.8;

    public function phpCurrent(): int
    {
        return memory_get_usage(true);
    }

    public function phpPeak(): int
    {
        return memory_get_peak_usage(true);
    }

    /**
     * Null when PHP has no limit (memory_limit = -1).
     */
    public function phpLimit(): ?int
    {
        $limit = self::parseShorthand((string) ini_get('memory_limit'));

        return (null === $limit || $limit < 0) ? null : $limit;
    }

    /**
     * Resident memory of the whole container, null outside a cgroup.
     */
    public function containerCurrent(): ?int
    {
        return self::readInt(self::CGROUP_V2_CURRENT) ?? self::readInt(self::CGROUP_V1_CURRENT);
    }

    /**
     * Null when the container runs uncapped: cgroup v2 writes "max", and v1
     * writes a sentinel so large it means the same thing.
     */
    public function containerLimit(): ?int
    {
        $limit = self::readInt(self::CGROUP_V2_MAX) ?? self::readInt(self::CGROUP_V1_MAX);

        if (null === $limit || $limit >= PHP_INT_MAX / 2) {
            return null;
        }

        return $limit;
    }

    /**
     * Fraction of the given limit already used, null when there is no limit.
     */
    public static function ratio(?int $used, ?int $limit): ?float
    {
        if (null === $used || null === $limit || $limit <= 0) {
            return null;
        }

        return $used / $limit;
    }

    public static function format(?int $bytes): string
    {
        if (null === $bytes) {
            return '-';
        }

        $units = ['o', 'K', 'M', 'G'];
        $unit = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf($value >= 100 || 0 === $unit ? '%.0f%s' : '%.1f%s', $value, $units[$unit]);
    }

    private static function readInt(string $path): ?int
    {
        if (!is_readable($path)) {
            return null;
        }

        $raw = trim((string) @file_get_contents($path));

        // cgroup v2 writes the literal "max" when uncapped.
        if ('' === $raw || !ctype_digit($raw)) {
            return null;
        }

        return (int) $raw;
    }

    private static function parseShorthand(string $value): ?int
    {
        $value = trim($value);

        if ('' === $value) {
            return null;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
