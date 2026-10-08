<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel\Import;

/**
 * The difference between two texts, line by line: what `cooper:import`
 * shows instead of overwriting a file. A longest-common-subsequence
 * diff, small and dependency-free -- the files are a page or two, and a
 * package for this would be one more thing for an application to carry.
 *
 * @internal
 */
final class LineDiff
{
    private function __construct()
    {
    }

    /**
     * `$old` against `$new`: unchanged lines prefixed `' '`, removed ones
     * `'-'`, added ones `'+'`, under `---`/`+++` headers naming them.
     *
     * @param string $old the text on disk
     * @param string $new the text that would replace it
     * @param string $oldLabel what `---` names
     * @param string $newLabel what `+++` names
     * @return string the diff, ending in a newline
     */
    public static function between(string $old, string $new, string $oldLabel, string $newLabel): string
    {
        $a = explode("\n", rtrim($old, "\n"));
        $b = explode("\n", rtrim($new, "\n"));
        $n = count($a);
        $m = count($b);

        // $lcs[$i][$j]: the longest common subsequence of $a[$i..] and $b[$j..].
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $out = "--- {$oldLabel}\n+++ {$newLabel}\n";
        [$i, $j] = [0, 0];
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                $out .= " {$a[$i]}\n";
                [$i, $j] = [$i + 1, $j + 1];
            } elseif ($j < $m && ($i === $n || $lcs[$i][$j + 1] >= $lcs[$i + 1][$j])) {
                $out .= "+{$b[$j]}\n";
                $j++;
            } else {
                $out .= "-{$a[$i]}\n";
                $i++;
            }
        }

        return $out;
    }
}
