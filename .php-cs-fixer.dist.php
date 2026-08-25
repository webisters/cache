<?php declare(strict_types=1);
/*
 * This file is part of Webisters Cache Library.
 *
 * (c) Hafiz Muhammad Moaz <thewebisters@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use Framework\CodingStandard\Config;
use Framework\CodingStandard\Finder;

return (new Config())->setDefaultHeaderComment(
    'Webisters Cache Library',
    'Hafiz Muhammad Moaz <thewebisters@gmail.com>'
)->setFinder(
    // demo/ is example code, meant to be read by someone meeting the library
    // for the first time. A licence header on each file and a backslash on
    // every constant would be noise in front of the thing being shown.
    Finder::create()->in(__DIR__)->exclude('demo')
);
