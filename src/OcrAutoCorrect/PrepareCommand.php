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

            break;

        }

        $this->out->success("{$numOk} files prepared successfully, {$numErrors} errors");

    }

    /**
     * @param string $content
     * @return string
     */
    protected function prepareContent(string $content): string
    {

        // Remove any non-ASCII characters
        $content = preg_replace('/[^[:ascii:]\s]/', '', $content);

        // Remove extraneous spaces
        $content = preg_replace('/[[:blank:]]{2,}/', ' ', $content);

        // Remove spaces before punctuation
        $content = preg_replace('/( )([[:punct:]])/', '$2', $content);

        // Convert any HTML entities
        $content = html_entity_decode($content);

        // Remove line breaks in between hyphenated words
        $content = preg_replace('/([[:alnum:]]+)(-)([[:blank:]]?)([\n\r]+)([[:alnum:]]+)/', '$1$5', $content);

        // Remove line breaks in paragraphs
        $content = preg_replace('/([[:alnum:][:punct:]]+)([[:blank:]]?)([\n\r])([[:alnum:][:punct:]]+)/', '$1 $4', $content);

        // Remove page numbers
        $content = preg_replace('/([\n\r]+)([[:digit:][:blank:][:punct:]]+)([\n\r]+)/', "\n", $content);

        // Remove extraneous line breaks
        $content = preg_replace('/([\n\r]{3,})/', "\n\n", $content);

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