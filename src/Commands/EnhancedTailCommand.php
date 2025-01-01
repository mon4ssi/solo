<?php

/**
 * @author Aaron Francis <aarondfrancis@gmail.com>
 * @link https://aaronfrancis.com
 * @link https://twitter.com/aarondfrancis
 */

namespace AaronFrancis\Solo\Commands;

use AaronFrancis\Solo\Facades\Solo;
use AaronFrancis\Solo\Hotkeys\Hotkey;
use AaronFrancis\Solo\Support\Screen;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Prompts\Concerns\Colors;
use Laravel\Prompts\Themes\Default\Concerns\InteractsWithStrings;

class EnhancedTailCommand extends Command
{
    use Colors, InteractsWithStrings;

    protected bool $hideVendor = true;

    protected int $compressed = 0;

    protected ?int $pendingScrollIndex = null;

    protected string $file;

    private string $invisibleVendorMark = "\e[8mV\e[28m";

    public static function forFile($path)
    {
        return static::make('Logs', "tail -f -n 100 $path")->setFile($path);
    }

    public function setFile($path)
    {
        $this->file = $path;

        return $this;
    }

    /**
     * @return array<string, Hotkey>
     */
    public function hotkeys(): array
    {
        return [
            'vendor' => Hotkey::make('v', $this->toggleVendorFrames(...))
                ->label($this->hideVendor ? 'Show Vendor' : 'Hide Vendor'),

            'truncate' => $this->file ? Hotkey::make('t', $this->truncateFile(...))
                ->label('Truncate') : null
        ];
    }

    protected function makeNewScreen()
    {
        // Disable wrapping by setting the width to 1000
        // characters. We'll wrap the lines ourselves.
        return new Screen(1000, $this->scrollPaneHeight());
    }

    protected function truncateFile()
    {
        if (!$this->file) {
            return;
        }

        // Opening in write mode truncates (or creates.)
        $handle = fopen($this->file, 'w');

        if ($handle !== false) {
            fclose($handle);
        }

        // Clear the logs held in memory.
        $this->clear();
    }

    protected function toggleVendorFrames()
    {
        $this->hideVendor ? $this->showVendorFrames() : $this->hideVendorFrames();
        $this->hideVendor = !$this->hideVendor;
    }

    protected function showVendorFrames()
    {
        $lines = $this->wrappedLines();
        $cursor = $this->scrollIndex;

        while ($cursor >= 0) {
            $line = $lines->get($cursor);

            // Invisible compressed line count.
            if ($count = Str::match("/\\e\[8m\[(\d+)]\\e\[28m/", $line)) {
                $this->pendingScrollIndex ??= $this->scrollIndex + intval($count);
            }

            // Need to offset for all the vendor lines that do remain. For example
            // if we've compressed 50 lines into 5, we can't offset the scroll
            // index by 50, because there are still 5 remaining. So we figure
            // out how many total lines have been compressed (50) and
            // how many remain (5).
            if ($this->isVendorFrame($line)) {
                $this->pendingScrollIndex -= 1;
            }

            $cursor--;
        }
    }

    protected function hideVendorFrames()
    {
        $lines = $this->wrappedLines();
        $cursor = $this->scrollIndex;

        $linesAreVendor = [];

        // Working our way backwards above the scroll index,
        // figure out if the lines are vendor or not.
        while ($cursor >= 0) {
            $line = $lines->get($cursor);
            $linesAreVendor[] = $this->isVendorFrame($line);
            $cursor--;
        }

        $totalVendorLines = 0;
        $remainingVendorLines = 0;

        $inChunk = false;

        // Now that we have the classification of all the lines, lets figure
        // out how many total vendor frames there are, and how many that
        // compresses down to. Each continuous chunk of vendor frames
        // gets collapsed down into a single line.
        foreach ($linesAreVendor as $value) {
            if ($value === true) {
                $totalVendorLines++;

                if (!$inChunk) {
                    $remainingVendorLines++;
                    $inChunk = true;
                }
            } elseif ($value === false) {
                $inChunk = false;
            }
        }

        $this->pendingScrollIndex = $this->scrollIndex - $totalVendorLines + $remainingVendorLines;
    }

    protected function modifyWrappedLines(Collection $lines): Collection
    {
        $this->compressed = 0;

        $lines = $lines
            ->map($this->formatLogLine(...))
            ->flatten()
            ->reject(fn($line) => is_null($line))
            ->when($this->hideVendor, $this->collapseVendorFrames(...));

        if (!is_null($this->pendingScrollIndex)) {
            $this->scrollIndex = max(0, min(
                $this->pendingScrollIndex,
                $lines->count() - $this->scrollPaneHeight()
            ));
            $this->pendingScrollIndex = null;
        }

        return $lines;
    }

    protected function collapseVendorFrames(Collection $lines)
    {
        $hasVendorFrame = false;

        return $lines
            // We reverse because we want to keep the *last* vendor frame,
            // because that line holds the cumulative compressed lines
            // number. If we kept the first vendor frame our scroll
            // index wouldn't work when toggling.
            ->reverse()
            ->filter(function ($line) use (&$hasVendorFrame, &$remainingVendorLines) {
                $isVendorFrame = $this->isVendorFrame($line);

                if ($isVendorFrame) {
                    // Skip the line if a vendor frame has already been added.
                    if ($hasVendorFrame) {
                        return false;
                    }
                    // Otherwise, mark that a vendor frame has been added.
                    $hasVendorFrame = true;
                } else {
                    // Reset the flag if the current line is not a vendor frame.
                    $hasVendorFrame = false;
                }

                return true;
            })
            // Put it back in the right orientation.
            ->reverse();
    }

    protected function formatInitialException($line): array
    {
        $lines = explode('{"exception":"[object] ', $line);
        $message = array_map(
            fn($line) => Solo::makeTheme()->exception($line),
            $this->wrapLine($lines[0])
        );

        $exception = array_map(
            fn($line) => ' ' . Solo::makeTheme()->exception($line),
            $this->wrapLine($lines[1], -1)
        );

        return [
            ...$message,
            ...$exception
        ];
    }

    protected function formatLogLine($line): null|array|string
    {
        $theme = Solo::makeTheme();

        // 1 space outside of each border.
        $traceBoxWidth = $this->scrollPaneWidth() - 2;

        // 1 border + 1 space on each side
        $traceContentWidth = $traceBoxWidth - 4;

        // A single trailing line that closes the JSON exception object.
        if (trim($line) === '"}') {
            return $theme->dim(' ╰' . str_repeat('═', $traceBoxWidth - 2) . '╯');
        }

        if (str_contains($line, '{"exception":"[object] ')) {
            return $this->formatInitialException($line);
        }

        if (str_contains($line, '[stacktrace]')) {
            return $theme->dim(' ╭─Trace' . str_repeat('─', $traceContentWidth - 4) . '╮');
        }

        if (!Str::isMatch('/#[0-9]+ /', $line)) {
            return $this->wrapLine($line);
        }

        $base = function_exists('Orchestra\Testbench\package_path') ? \Orchestra\Testbench\package_path() : base_path();

        // Make the line shorter by removing the base path, which helps prevent wrapping.
        $line = str_replace($base, '', $line);

        // Stack trace lines start with #\d. Here we pad the numbers 0-9
        // with a preceding zero to keep everything in line visually.
        $line = preg_replace('/^#(\d)(?!\d)/', '#0$1', $line);

        $vendor = $this->isVendorFrame($line);

        if ($this->hideVendor && $vendor) {
            // Count up how many lines this *would* have occupied, if it had been shown.
            $this->compressed += count($this->wrapLine($line, $traceContentWidth, 4));

            // Add the running total, invisibly, to this line. When we turn vendor
            // frames back on we search through the lines above the current index
            // to figure out how many compressed vendor frames there are.
            $invisibleCount = "\e[8m[$this->compressed]\e[28m";

            // We also add the invisible vendor mark to denote that these
            // are vendor frames, albeit collapsed ones.
            return $this->dim(
                ' │ ' . $this->pad("#… {$invisibleCount} {$this->invisibleVendorMark}", $traceContentWidth) . ' │ '
            );
        }

        $line = $this->highlightFileOnly($line);

        return array_map(function ($line) use ($traceContentWidth, $vendor) {
            return $this->dim(' │ ')
                . $this->pad($line, $traceContentWidth)
                . $this->dim(' │')
                . ($vendor ? $this->invisibleVendorMark : ' ');
        }, $this->wrapLine($line, $traceContentWidth, 4));
    }

    protected function highlightFileOnly($line)
    {
        // ^ and $: Start and end of line
        // (#\d+): Capture the # followed by digits (this is the chunk we dim)
        // (.*?): Capture everything up to a colon if it exists (non-greedy)
        // (:.*)?: Capture an optional colon and everything after it
        $pattern = '/^(#\d+)(.*?)(:.*)?$/';

        // We then apply dim (\033[2m) and reset (\033[0m) codes. Reference $1 is
        // the frame number, $2 is the file, $3 is after the file, which is
        // usually the method. Dim the number and method parts,
        // leave the file part as is.
        $replacement = "\033[2m$1\033[0m$2\033[2m$3\033[0m";

        return preg_replace($pattern, $replacement, $line);
    }

    protected function isVendorFrame($line)
    {
        return
            str_contains($line, $this->invisibleVendorMark)
            ||
            str_contains($line, '/vendor/') && !Str::isMatch("/BoundMethod\.php\([0-9]+\): App/", $line)
            ||
            str_ends_with($line, '{main}');
    }
}
