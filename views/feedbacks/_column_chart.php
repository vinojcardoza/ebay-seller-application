<?php

/**
 * Partial: single-series column chart with hover/focus tooltips and a table view.
 *
 * One series, so no legend: the title names it. Bars grow from one baseline,
 * capped at 24px wide with a 4px rounded top; the whole column slot is the
 * hover/focus target. Every value is also in the "Show as table" view.
 *
 * @var \League\Plates\Template\Template $this
 * @var string $id Unique element ID prefix.
 * @var string $title Chart title.
 * @var string $subtitle Period shown.
 * @var string $unit Noun for values in labels, e.g. "feedback".
 * @var array{points: list<array{label: string, axis: string, value: int}>, ticks: list<int>, max: int,
 *     total: int} $series
 */

/**
 * Format a count for display, e.g. 1234 → "1,234".
 */
$format = static fn (int $n): string => number_format($n);
?>
<figure class="column-chart" aria-labelledby="<?php echo $this->e($id); ?>-title">
    <figcaption class="column-chart-caption">
        <h2 id="<?php echo $this->e($id); ?>-title"><?php echo $this->e($title); ?></h2>
        <span class="text-body-secondary small">
            <?php echo $this->e($subtitle); ?> · <?php echo $format($series['total']); ?> total
        </span>
    </figcaption>

    <div class="column-chart-plot">
        <div class="column-chart-grid" aria-hidden="true">
            <?php foreach ($series['ticks'] as $tick) : ?>
                <div class="column-chart-gridline" style="bottom: <?php echo $tick / $series['max'] * 100; ?>%">
                    <span><?php echo $format($tick); ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="column-chart-bars" role="list">
            <?php foreach ($series['points'] as $point) : ?>
                <div class="column-chart-bar" role="listitem" tabindex="0"
                     aria-label="<?php echo $this->e($point['label'] . ': ' . $format($point['value']) . ' ' . $unit); ?>"
                     data-tip-value="<?php echo $this->e($format($point['value']) . ' ' . $unit); ?>"
                     data-tip-label="<?php echo $this->e($point['label']); ?>">
                    <?php if ($point['value'] > 0) : ?>
                        <span class="column-chart-fill"
                              style="height: <?php echo $point['value'] / $series['max'] * 100; ?>%"></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="column-chart-axis" aria-hidden="true">
        <?php foreach ($series['points'] as $point) : ?>
            <span><?php echo $this->e($point['axis']); ?></span>
        <?php endforeach; ?>
    </div>

    <details class="column-chart-table">
        <summary>Show as table</summary>
        <table class="table table-sm mt-2">
            <thead>
                <tr><th scope="col">Period</th><th scope="col" class="text-end">Count</th></tr>
            </thead>
            <tbody>
                <?php foreach ($series['points'] as $point) : ?>
                    <tr>
                        <td><?php echo $this->e($point['label']); ?></td>
                        <td class="text-end"><?php echo $format($point['value']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </details>
</figure>
