<?php
/**
 * Small HTML/CSS chart helpers for the analytics pages. Each returns markup;
 * values arrive already counted from the database. Charts follow one spec:
 * thin marks with 4px rounded data-ends, hairline solid gridlines, text in
 * ink colours (never the series colour), a legend for 2+ series, a hover /
 * focus tooltip on every mark (public/js/charts.js) and a table view.
 */

if (!function_exists('chart_nice_max')) {
    /** A round axis maximum (1, 2, 5 x 10^n) at or above $max, and its tick step. */
    function chart_nice_max(int $max, int $ticks = 4): array
    {
        if ($max <= 0) {
            return [$ticks, 1];
        }
        $raw = $max / $ticks;
        $mag = 10 ** floor(log10($raw));
        foreach ([1, 2, 5, 10] as $m) {
            if ($m * $mag >= $raw) {
                $step = (int) max(1, $m * $mag);
                return [$step * (int) ceil($max / $step), $step];
            }
        }
        return [$max, (int) ceil($max / $ticks)];
    }

    function chart_num(int|float $n): string
    {
        return number_format($n);
    }

    /**
     * Stacked columns (one column per category, segments per series).
     *
     * @param array  $categories  key => axis label
     * @param array  $series      key => [label, css colour variable]
     * @param array  $values      category key => series key => int
     * @param string $caption     what the chart shows (screen readers, table caption)
     */
    function chart_stacked_columns(array $categories, array $series, array $values, string $caption): string
    {
        $totals = array_map(fn($k) => array_sum(array_intersect_key($values[$k] ?? [], $series)), array_keys($categories));
        [$axisMax, $step] = chart_nice_max($totals ? max($totals) : 0);
        $labelEvery = count($categories) > 18 ? 3 : (count($categories) > 12 ? 2 : 1);

        $html = '<div class="vz-legend" aria-hidden="true">';
        foreach ($series as [$label, $color]) {
            $html .= '<span class="vz-key"><i style="background:var(' . $color . ')"></i>' . e($label) . '</span>';
        }
        $html .= '</div><div class="vz-cols" role="img" aria-label="' . e($caption) . '">';
        $html .= '<div class="vz-yaxis" aria-hidden="true">';
        for ($t = $axisMax; $t >= 0; $t -= $step) {
            $html .= '<span style="bottom:' . ($t / $axisMax * 100) . '%">' . chart_num($t) . '</span>';
        }
        $html .= '</div><div class="vz-plot">';
        for ($t = $step; $t <= $axisMax; $t += $step) {
            $html .= '<div class="vz-grid" style="bottom:' . ($t / $axisMax * 100) . '%"></div>';
        }
        $i = 0;
        foreach ($categories as $key => $label) {
            $total = 0;
            $tip = $label;
            $segments = '';
            foreach ($series as $sKey => [$sLabel, $color]) {
                $v = (int) ($values[$key][$sKey] ?? 0);
                $total += $v;
                $tip .= "\n" . $sLabel . ': ' . chart_num($v);
                if ($v > 0) {
                    $segments .= '<div class="vz-seg" style="height:' . ($v / $axisMax * 100) . '%;background:var(' . $color . ')"></div>';
                }
            }
            if (count($series) > 1) {
                $tip .= "\nTotal: " . chart_num($total);
            }
            $html .= '<div class="vz-col" tabindex="0" data-tip="' . e($tip) . '" aria-label="' . e(str_replace("\n", ', ', $tip)) . '">'
                . '<div class="vz-stack">' . $segments . '</div>'
                . '<div class="vz-xlabel"' . ($i % $labelEvery ? ' style="visibility:hidden"' : '') . '>' . e($label) . '</div></div>';
            $i++;
        }
        $html .= '</div></div>';

        // Table view: every value, so nothing depends on the chart alone.
        $rows = '';
        foreach ($categories as $key => $label) {
            $rows .= '<tr><th scope="row">' . e($label) . '</th>';
            $sum = 0;
            foreach ($series as $sKey => $_) {
                $v = (int) ($values[$key][$sKey] ?? 0);
                $sum += $v;
                $rows .= '<td>' . chart_num($v) . '</td>';
            }
            $rows .= (count($series) > 1 ? '<td>' . chart_num($sum) . '</td>' : '') . '</tr>';
        }
        $head = '<th scope="col"></th>' . implode('', array_map(fn($s) => '<th scope="col">' . e($s[0]) . '</th>', $series)) . (count($series) > 1 ? '<th scope="col">Total</th>' : '');
        return $html . chart_table($caption, $head, $rows);
    }

    /**
     * Horizontal bars, one series, value at the tip.
     *
     * @param array  $rows  [label, value, ?note] in display order
     */
    function chart_bars(array $rows, string $caption, string $color = '--series-1', string $unit = ''): string
    {
        $max = max(1, ...array_map(fn($r) => (int) $r[1], $rows ?: [[null, 0]]));
        $html = '<div class="vz-bars" role="img" aria-label="' . e($caption) . '">';
        foreach ($rows as $r) {
            $v = (int) $r[1];
            $tip = $r[0] . ': ' . chart_num($v) . ($unit ? ' ' . $unit : '') . (!empty($r[2]) ? "\n" . $r[2] : '');
            $html .= '<div class="vz-bar-row" tabindex="0" data-tip="' . e($tip) . '" aria-label="' . e(str_replace("\n", ', ', $tip)) . '">'
                . '<span class="vz-bar-label">' . e($r[0]) . '</span>'
                . '<span class="vz-bar-track"><span class="vz-bar" style="width:' . ($v > 0 ? max(0.8, $v / $max * 100) : 0) . '%;background:var(' . $color . ')"></span>'
                . '<span class="vz-bar-value">' . chart_num($v) . '</span></span></div>';
        }
        $html .= '</div>';
        $body = '';
        foreach ($rows as $r) {
            $body .= '<tr><th scope="row">' . e($r[0]) . '</th><td>' . chart_num((int) $r[1]) . '</td></tr>';
        }
        return $html . chart_table($caption, '<th scope="col"></th><th scope="col">' . e($unit !== '' ? ucfirst($unit) : 'Count') . '</th>', $body);
    }

    /** A meter: $value of $max, fill on a lighter track of the same hue. */
    function chart_meter(int $value, int $max, string $label): string
    {
        $pct = $max > 0 ? min(100, round($value / $max * 100)) : 0;
        return '<div class="vz-meter" role="meter" aria-valuemin="0" aria-valuemax="' . $max . '" aria-valuenow="' . $value . '" aria-label="' . e($label) . '" tabindex="0" data-tip="' . e($label . ': ' . chart_num($value) . ' of ' . chart_num($max) . ' (' . $pct . '%)') . '">'
            . '<span style="width:' . $pct . '%"></span></div>';
    }

    function chart_table(string $caption, string $headCells, string $bodyRows): string
    {
        return '<details class="vz-table"><summary>Show as table</summary><div class="table-responsive"><table class="table table-sm">'
            . '<caption>' . e($caption) . '</caption><thead><tr>' . $headCells . '</tr></thead><tbody>' . $bodyRows . '</tbody></table></div></details>';
    }
}
