<?php

namespace OcrAutoCorrect;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'prepare',
    description: 'Prepares text files to be auto-corrected by removing noise.',
)]
class PrepareCommand extends Command
{

    const CONTENT_SAMPLE_LENGTH = 1024;
    protected bool $useDb = false;

    /**
     * @return void
     */
    protected function processFiles(): void
    {

        $outputDir = $this->getOutputDir();
        $files = $this->getFiles($this->in->getArgument('path'));
        $numFiles = number_format(count($files));
        $this->out->title("Preparing {$numFiles} files ...");

        $this->setDict();

        $numOk = 0;
        $numErrors = 0;
        foreach ($files as $file)
        {

            $file = realpath($file);
            $filename = basename($file);

            $this->out->write("{$filename} ... ");
            $content = $this->prepareContent(file_get_contents($file));

            $outputPath = "{$outputDir}/{$filename}";
            if (file_put_contents($outputPath, $content)) {
                $this->out->writeln("<fg=green>OK</>");
                ++$numOk;
            } else {
                $this->out->writeln("<fg=red>ERROR</>");
                ++$numErrors;
            }

        }

        $this->out->success("{$numOk} files prepared successfully, {$numErrors} errors");

    }

    /**
     * @param string $content
     * @return string
     */
    protected function prepareContent(string $content): string
    {

        $replacements = [
            "‘" => "'", // Convert weird apostrophes to standard ASCII ones
            "’" => "'", // Convert weird apostrophes to standard ASCII ones
            '“' => '"', // Convert weird quotes to standard ones
            '”' => '"', // Convert weird quotes to standard ones
            "—" => " — ", // Convert weird dash to mdash with spaces on either side
            "--" => " — ", // Convert double dash to mdash with spaces on either side
            "/([[:alnum:]])( )([\.\,\?\!;:\)\]])/" => '$1$3', // Remove spaces before punctuation
            ". . ." => ' … ', // Fix ellipsis
            "/([[:alnum:]]+)([-¬])([[:blank:]]?)([\n]+)([[:alnum:]]+)/" => '$1$5', // Remove line breaks in between hyphenated words
            "_" => '', // Remove underscores
            "|" => 'I', // Replace | with I
            "/([[:alnum:]]+)([\[\(])/" => '$1 $2', // Don't allow a parentheses without a space before it
            "/([\[\(])( )/" => '$1', // Force no space after an open parentheses
            "/\r?\n(?!\r?\n)/" => ' ', // Remove line breaks in paragraphs
            "/\n/" => "\n\n", // Make any paragraph breaks two line breaks
            "/(\n)([[:blank:]])+/" => '$1', // Don't allow spaces at the beginning of a line
            "/([\n]+)([[:digit:][:blank:][:punct:]]+)([\n]+)/" => "\n", // Remove page numbers
            "''" => '"', // Replace double apostrophe with quote
            "/([\n]{3,})/" => "\n\n", // Remove extraneous line breaks
            "/[[:blank:]]{2,}/" => ' ', // Remove extraneous spaces
        ];

        foreach ($replacements as $find=>$replace)
        {
            if (str_starts_with($find, '/')) {
                $content = preg_replace($find, $replace, $content);
            } else {
                $content = str_replace($find, $replace, $content);
            }
        }

        // Cut out gobbledygook at beginning and end by starting w/ 1st real word and ending w/ last real word
        $content = $this->trimToWords($content);

        return $content;

    }

    /**
     * @param string $content
     * @return string
     */
    protected function trimToWords(string $content): string
    {

        $words = $this->parseWords($content);
        $numWords = count($words);

        $firstWord = null;
        for ($i = 0; $i < $numWords; ++$i)
        {
            if (
                isset($words[$i]) &&
                (strlen($words[$i]) > 1) &&
                pspell_check($this->dict, $words[$i])
            ) {
                $firstWord = $words[$i];
                break;
            }
        }

        $lastWord = null;
        for ($i = $numWords; $i >= 0; --$i)
        {
            if (
                isset($words[$i]) &&
                (strlen($words[$i]) > 1) &&
                pspell_check($this->dict, $words[$i])
            ) {
                $lastWord = $words[$i];
                break;
            }
        }

        $startPos = strpos($content, $firstWord);
        $endPos = strrpos($content, $lastWord) + strlen($lastWord) + 1;
        $content = substr($content, $startPos, $endPos-$startPos);

        return $content;
    }

}