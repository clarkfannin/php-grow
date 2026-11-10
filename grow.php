<?php

class World
{
    public static array $size;

    public Grid $grid;
    public array $drops;
    public array $stalks;
    public array $lockedCols;

    function __construct()
    {
        self::$size = $this->getTerminalSize();
        $this->grid = new Grid();
        $this->drops = [];
        $this->stalks = [];
        $this->lockedCols = [];
    }

    function getTerminalSize()
    {
        $rows = exec('tput lines');
        $cols = exec('tput cols');
        return ["rows" => $rows, "cols" => $cols];
    }

    function getColumnLocks()
    {
        $totalCols = World::$size["cols"];
        $numLocked = rand(round($totalCols / 1.5), $totalCols);
        $result = [];
        while (count($result) < $numLocked) {
            $rand = rand(1, $totalCols);
            if (!in_array($rand, $result)) {
                array_push($result, $rand);
            }
        }
        $this->lockedCols = $result;
    }

    function spawnDrop()
    {
        $totalCols = World::$size["cols"];
        $randomCol = rand(1, $totalCols);
        $drop = new Drop($randomCol);
        array_push($this->drops, $drop);
        $this->grid->set($drop->row, $drop->col, $drop->char, $drop->colorCode);
    }

    function spawnStalk(int $col)
    {
        $stalk = new Stalk($col);
        $char = $stalk->getChar();
        $this->stalks[$col] = $stalk;
        $this->grid->set($stalk->row, $stalk->col, $char, $stalk->colorCode);
        return $stalk;
    }

    function updateDrops()
    {
        foreach ($this->drops as $i => $drop) {
            $stalkColor = "92";
            $soilColor = "38;5;130";
            $sunColor = "0;33";
            $flowerColors = ["0;35", "0;31", "0;37", "0;34"];
            $below = $this->grid->get($drop->row + 1, $drop->col)["colorCode"];
            $current = $this->grid->get($drop->row, $drop->col)["colorCode"];

            // always clear old position first (unless sun)
            if ($current !== $sunColor) {
                $this->grid->set($drop->row, $drop->col, " ", " ");
            }

            if (in_array($below, $flowerColors)) {
                continue;
            }

            // drop is right above stalk - grow that stalk and remove drop
            if ($below === $stalkColor) {
                $stalk = $this->stalks[$drop->col];
                if (rand(1, 20) === 1) {
                    // bloom stalk if it reaches max height
                    if ($stalk->row <= $stalk->maxHeight) {
                        $stalk->bloom();
                    } else {
                        $stalk->grow();
                    }
                }
                unset($this->drops[$i]);
                // drop is right above soil - remove drop, chance to spawn stalk
            } else if ($below === $soilColor) {
                if (rand(1, 100) === 1 && !in_array($drop->col, $this->lockedCols)) {
                    $this->spawnStalk($drop->col);
                }
                unset($this->drops[$i]);
                // drop is above soil and not above a stalk
            } else {
                $drop->fall();
                // don't paint over the sun - let the drop pass behind it
                $newCell = $this->grid->get($drop->row, $drop->col)["colorCode"];
                if ($newCell !== $sunColor) {
                    $this->grid->set($drop->row, $drop->col, $drop->char, $drop->colorCode);
                }
            }
        }
    }

    function updateStalks()
    {
        foreach ($this->stalks as $stalk) {
            $start = $stalk->row;
            if (isset($stalk->isFlowered)) {
                $this->grid->set($stalk->row, $stalk->col, $stalk->flowerChar, $stalk->flowerColorCode);
                $start = $stalk->row + 1;
            }
            $char = $stalk->getChar();
            for ($row = $start; $row <= World::$size["rows"] - 1; $row++) {
                $this->grid->set($row, $stalk->col, $char, $stalk->colorCode);
            }
        }
    }


    function run()
    {
        Renderer::hideCursor();
        Renderer::clearTerminal();
        $this->getColumnLocks();

        while (true) {
            $this->grid->clear();
            $this->grid->setupSoil();
            $this->grid->setupSun();
            $this->spawnDrop();
            $this->updateStalks();
            $this->updateDrops();
            $this->grid->render();
            usleep(50000);
        }
    }
}

class Grid
{

    public array $cells;

    function __construct()
    {
        $this->cells = $this->setupCells();
    }

    function setupCells()
    {
        $cells = [];
        for ($row = 0; $row <= World::$size["rows"]; $row++) {
            for ($col = 0; $col <= World::$size["cols"]; $col++) {
                $cells[$row][$col] = ["char" => "", "colorCode" => ""];
            }
        }
        $this->setupSoil();
        $this->setupSun();
        return $cells;
    }

    function setupSoil()
    {
        for ($col = 1; $col < World::$size["cols"]; $col++) {
            $colorCode = "38;5;130"; // brown
            $char = "D";             // for dirt
            $this->set(World::$size["rows"], $col, $char, $colorCode);
        }
    }

    function setupSun()
    {
        for ($col = World::$size["cols"] - 6; $col < World::$size["cols"] - 2; $col++) {
            for ($row = 2; $row <= 4; $row++) {
                $colorCode = "0;33";
                $char = "S";
                $this->set($row, $col, $char, $colorCode);
            }
        }
    }

    function clear()
    {
        foreach ($this->cells as $row => $cols) {
            foreach (array_keys($cols) as $col) {
                $this->cells[$row][$col] = ["char" => "", "colorCode" => ""];
            }
        }
    }

    function get(int $row, int $col)
    {
        return $this->cells[$row][$col] ?? ["char" => "", "colorCode" => ""];
    }

    function set(int $row, int $col, string $char, string $colorCode)
    {
        $this->cells[$row][$col] = ["char" => $char, "colorCode" => $colorCode];
    }

    function at(int $row, int $col)
    {
        return $this->cells[$row][$col];
    }

    function render()
    {
        foreach ($this->cells as $row => $cols) {
            foreach ($cols as $col => $cell) {
                if ($cell["char"] !== "") {
                    Renderer::moveTo($row, $col);
                    Renderer::printWithColor($cell["char"], $cell["colorCode"]);
                }
            }
        }
    }
}

class Drop
{
    public int $row;
    public int $col;
    public string $char;
    public string $colorCode;

    function __construct(int $col)
    {
        $this->col = $col;
        $this->row = 1;
        $this->char = ".";
        $this->colorCode = "36";
    }

    function fall()
    {
        $this->row++;
    }
}

class Stalk
{
    public int $row;
    public int $col;
    public array $charChoices;
    public string $colorCode;
    public int $maxHeight;
    public array $flowerCharChoices;
    public array $flowerColorCodeChoices;
    public string $flowerChar;
    public string $flowerColorCode;
    public bool $isFlowered;

    function __construct(int $col)
    {
        $this->col = $col;
        $this->row = World::$size["rows"] - 1;
        $this->charChoices = ["|"];
        $this->colorCode = "92";
        $this->maxHeight = rand(2, World::$size["rows"] - 2);

        $this->flowerCharChoices = ["✽", "✾", "✿", "❀", "❁", "❃", "❊", "❋", "✣", "✤", "ꕤ", "ꕥ"];


        $this->flowerColorCodeChoices = ["0;35", "0;31", "0;37", "0;34"];
    }
    function getChar()
    {
        $randKey = array_rand($this->charChoices);
        return $this->charChoices[$randKey];
    }

    function grow()
    {
        $this->row--;
    }

    function bloom()
    {
        $char_key = array_rand($this->flowerCharChoices, 1);
        $this->flowerChar = $this->flowerCharChoices[$char_key];

        $color_key = array_rand($this->flowerColorCodeChoices, 1);
        $this->flowerColorCode = $this->flowerColorCodeChoices[$color_key];

        $this->isFlowered = true;
    }
}

class Renderer
{
    public static function hideCursor()
    {
        echo "\x1b[?25l";
    }

    public static function clearTerminal()
    {
        echo "\x1bc";
    }

    public static function moveTo(int $row, int $col)
    {
        echo "\x1b[{$row};{$col}H";
    }

    public static function printWithColor(string $str, string $code)
    {
        echo "\x1b[{$code}m" . $str . "\x1b[0m";
    }
}

$world = new World();
$world->run();
