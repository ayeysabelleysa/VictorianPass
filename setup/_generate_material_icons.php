<?php
$size = 128;
$green = [35, 65, 46, 255];
$gold = [205, 151, 49, 255];

function makeCanvas($size) {
    return array_fill(0, $size * $size, [0, 0, 0, 0]);
}

function dot(&$pixels, $x, $y, $radius, $color) {
    global $size;
    $radiusSquared = $radius * $radius;
    for ($row = max(0, (int) floor($y - $radius)); $row <= min($size - 1, (int) ceil($y + $radius)); $row++) {
        for ($column = max(0, (int) floor($x - $radius)); $column <= min($size - 1, (int) ceil($x + $radius)); $column++) {
            if (($column - $x) ** 2 + ($row - $y) ** 2 <= $radiusSquared) {
                $pixels[$row * $size + $column] = $color;
            }
        }
    }
}

function line(&$pixels, $x1, $y1, $x2, $y2, $color, $width = 6) {
    $steps = max(1, (int) (max(abs($x2 - $x1), abs($y2 - $y1)) * 2));
    for ($step = 0; $step <= $steps; $step++) {
        $ratio = $step / $steps;
        dot($pixels, $x1 + ($x2 - $x1) * $ratio, $y1 + ($y2 - $y1) * $ratio, $width / 2, $color);
    }
}

function polyline(&$pixels, $points, $color, $width = 6) {
    for ($index = 1; $index < count($points); $index++) {
        line($pixels, $points[$index - 1][0], $points[$index - 1][1], $points[$index][0], $points[$index][1], $color, $width);
    }
}

function ellipse(&$pixels, $centerX, $centerY, $radiusX, $radiusY, $color, $width = 6) {
    $previous = null;
    for ($degree = 0; $degree <= 360; $degree += 3) {
        $radians = deg2rad($degree);
        $point = [$centerX + cos($radians) * $radiusX, $centerY + sin($radians) * $radiusY];
        if ($previous !== null) {
            line($pixels, $previous[0], $previous[1], $point[0], $point[1], $color, $width);
        }
        $previous = $point;
    }
}

function savePng($name, $pixels) {
    global $size;
    $raw = '';
    for ($row = 0; $row < $size; $row++) {
        $raw .= "\0";
        for ($column = 0; $column < $size; $column++) {
            $raw .= pack('C4', ...$pixels[$row * $size + $column]);
        }
    }
    $chunk = function ($type, $data) {
        return pack('N', strlen($data)) . $type . $data . pack('N', hexdec(hash('crc32b', $type . $data)));
    };
    $png = "\x89PNG\r\n\x1a\n";
    $png .= $chunk('IHDR', pack('NNC5', $size, $size, 8, 6, 0, 0, 0));
    $png .= $chunk('IDAT', gzcompress($raw, 9));
    $png .= $chunk('IEND', '');
    file_put_contents(__DIR__ . '/../images/accepted-materials/' . $name . '.png', $png);
}

if (!is_dir(__DIR__ . '/../images/accepted-materials')) {
    mkdir(__DIR__ . '/../images/accepted-materials', 0777, true);
}

$paper = makeCanvas($size);
polyline($paper, [[36, 19], [75, 19], [92, 36], [92, 108], [36, 108], [36, 19]], $green, 6);
polyline($paper, [[75, 20], [75, 37], [91, 37]], $gold, 5);
line($paper, 48, 55, 80, 55, $green, 5);
line($paper, 48, 69, 80, 69, $green, 5);
line($paper, 48, 83, 73, 83, $green, 5);
savePng('paper-document', $paper);

$bottle = makeCanvas($size);
polyline($bottle, [[53, 18], [75, 18], [75, 32], [70, 36], [70, 48], [83, 57], [87, 98], [82, 107], [46, 107], [41, 98], [45, 57], [58, 48], [58, 36], [53, 32], [53, 18]], $green, 6);
line($bottle, 50, 67, 78, 67, $gold, 5);
line($bottle, 49, 84, 80, 84, $gold, 5);
line($bottle, 53, 25, 75, 25, $gold, 4);
savePng('plastic-bottle', $bottle);

$can = makeCanvas($size);
line($can, 41, 29, 41, 99, $green, 6);
line($can, 87, 29, 87, 99, $green, 6);
ellipse($can, 64, 30, 23, 8, $green, 6);
ellipse($can, 64, 99, 23, 8, $green, 6);
ellipse($can, 64, 29, 14, 4, $gold, 4);
ellipse($can, 64, 29, 5, 2, $green, 3);
line($can, 51, 68, 77, 68, $gold, 5);
line($can, 51, 79, 77, 79, $gold, 5);
savePng('aluminum-can', $can);