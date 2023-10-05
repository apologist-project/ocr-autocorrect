<?php

namespace OcrAutoCorrect;

use Symfony\Component\Console\Command\Command as BaseCommand;
use SQLite3;
use PSpell\Dictionary;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class Command extends BaseCommand
{

    const PUNCT_TRIM = '.,"\'-;:*?[](){}!';
    const CONTEXT_SIZE = 20;
    const SUGGESTION_LIMIT = 10;
    const CHOICES = [];
    protected InputInterface $in;
    protected SymfonyStyle $out;
    protected SQLite3 $db;
    protected Dictionary $dict;
    protected bool $useDb = true;

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->setIO($input, $output);
        $this->processFiles();
        return BaseCommand::SUCCESS;
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'Path to text file(s)')
            ->addArgument('output', InputArgument::OPTIONAL, 'Path save processed text file(s)')
            ->addOption('lang', 'l', InputOption::VALUE_OPTIONAL, 'Language', 'en')
            ->addOption('dry-run', 'r', InputOption::VALUE_NONE, 'Dry run mode')
            ->addOption('exclude-hyphenated', 'e', InputOption::VALUE_NONE, 'Exclude hyphenated words')
        ;
        if ($this->useDb) {
            $this
                ->addOption('db-file', 'd', InputOption::VALUE_OPTIONAL, 'SQLite database file', './corrections.db')
                ->addOption('dict-threshold', 't', InputOption::VALUE_OPTIONAL, 'Threshold to add correction to dictionary', 2)
            ;
        }
    }

    /**
     * @param InputInterface $in
     * @param OutputInterface $out
     * @return void
     */
    protected function setIO(InputInterface $in, OutputInterface $out): void
    {
        $this->in = $in;
        $this->out = new SymfonyStyle($in, $out);
    }

    /**
     * @return void
     */
    protected function initDb(): void {
        $this->db = new SQLite3($this->in->getOption('db-file'));
        $res = $this->db->query("
            CREATE TABLE IF NOT EXISTS 
                corrections 
            (
                id INTEGER PRIMARY KEY, 
                error TEXT,
                correction TEXT,
                context TEXT,
                file TEXT,
                auto INTEGER
            )
        ");
        $res = $this->db->query("
            CREATE INDEX IF NOT EXISTS
                corrections_correction_idx
            ON
                corrections (correction)
        ");
        $res = $this->db->query("
            CREATE INDEX IF NOT EXISTS
                corrections_error_idx
            ON
                corrections (error)
        ");
    }

    /**
     * @return void
     */
    protected function initDict(): void
    {

        $this->setDict();

        $qry = $this->db->prepare("
            SELECT
                COUNT(error) AS `count`,
                correction
            FROM
                corrections
            GROUP BY
                correction
            HAVING
                `count` >= :min_count
            ORDER BY
                correction ASC
        ");
        $qry->bindValue(':min_count', $this->in->getOption('dict-threshold'), SQLITE3_INTEGER);
        $res = $qry->execute();

        while ($row = $res->fetchArray()) {
            foreach (preg_split('/[ -]/', $row['correction']) as $word)
            {
                @pspell_add_to_session($this->dict, preg_replace('/\W/', '', $word));
            }
        }

    }

    /**
     * @return void
     */
    protected function setDict(): void
    {
        $config = pspell_config_create($this->in->getOption('lang'));
        pspell_config_mode($config, PSPELL_FAST);
        $this->dict = pspell_new_config($config);
    }

    /**
     * @param string $str
     * @return array
     */
    public function getSuggestions(string $str): array
    {

        $qry = $this->db->prepare("
            SELECT
                COUNT(error) AS `count`,
                correction
            FROM
                corrections
            WHERE
                error = :str
            GROUP BY
                correction
            ORDER BY
                `count` DESC,
                correction ASC
        ");
        $qry->bindValue(':str', $str, SQLITE3_TEXT);
        $res = $qry->execute();

        // Populate suggestions from most common existing corrections
        $suggestions = [];
        while ($row = $res->fetchArray()) {
            $suggestions[] = $row['correction'];
        }

        // Add suggestions from PSPELL
        foreach (pspell_suggest($this->dict, $str) as $suggestion) {
            if (!in_array($suggestion, $suggestions) && (!in_array($suggestion, array_keys(static::CHOICES)))) {
                $suggestions[] = $suggestion;
                if (count($suggestions) > static::SUGGESTION_LIMIT) {
                    break;
                }
            }
        }

        return $suggestions;

    }

    /**
     * @return void
     */
    protected function processFiles(): void
    {

        $this->initDb();
        $this->initDict();

        $outputDir = $this->getOutputDir();

        $files = $this->getFiles($this->in->getArgument('path'));
        foreach ($files as $file)
        {

            $file = realpath($file);
            $filename = basename($file);
            $this->out->title($file);

            $content = file_get_contents($file);
            $words = $this->parseWords($content);
            $numWords = count($words);
            $replaced = [];
            $skipped = [];

            for ($i = 1; $i < $numWords; ++$i) {

                $word = trim($words[$i], static::PUNCT_TRIM);
                if (
                    (!str_contains($word, '-') || !$this->in->getOption('exclude-hyphenated')) &&
                    !preg_match('/([a-zA-Z][\.])+/', $word) && // Don't flag acronyms
                    !preg_match('/([\d\:])+/', $word) && // Don't flag numbers and verses
                    !filter_var($word, FILTER_VALIDATE_URL) && // Don't flag URLs
                    !pspell_check($this->dict, $word)
                ) {

                    $perc = number_format(($i / $numWords) * 100, 2);
                    $this->out->section("{$word} ({$perc}%)");

                    $context = '';
                    for ($n = $i-static::CONTEXT_SIZE; $n < $i; ++$n)
                    {
                        if (isset($words[$n])) {
                            $context .= "{$words[$n]} ";
                        }
                    }
                    $context .= $word;
                    for ($n = $i+1; $n < $i+(static::CONTEXT_SIZE+1); ++$n)
                    {
                        if (isset($words[$n])) {
                            $context .= " {$words[$n]}";
                        }
                    }
                    $this->out->write(str_replace($word, "<fg=white;bg=red>{$word}</>", $context));

                    $suggestions = $this->getSuggestions($word);
                    $replacement = $this->getReplacement(
                        $word,
                        $suggestions,
                        $words,
                        $i,
                        $context,
                        $file
                    );
                    $error = $replacement['error'];
                    $correction = $replacement['correction'];

                    if ($replacement['ff']) {
                        ++$i;
                    }

                    if (!is_null($correction)) {

                        if ($error != $correction) {
                            $replaced[$error] = $correction;
                        } else {
                            $skipped[] = $error;
                        }

                        preg_match("/\W" . preg_quote($error) . "\W/", $content, $matches, PREG_OFFSET_CAPTURE);
                        if (!empty($matches)) {
                            foreach ($matches as $match)
                            {
                                $pos = $match[1]+1;
                                $content = substr_replace($content, $correction, $pos, strlen($error));
                            }
                        }

                        if (!$this->in->getOption('dry-run')) {

                            $outputPath = "{$outputDir}/{$filename}";
                            if (file_put_contents($outputPath, $content)) {
                                $this->out->newLine(2);
                                if (empty($correction)) {
                                    $this->out->text("Removed <fg=red>{$error}</>");
                                } else if ($correction == $error) {
                                    $this->out->text("Kept <fg=green>{$correction}</>");
                                } else {
                                    $this->out->text("Replaced <fg=red>{$error}</> with <fg=green>{$correction}</>");
                                }
                            } else {
                                $this->out->error("Error writing to {$outputPath}");
                            }

                        }

                    } else {
                        $skipped[] = $error;
                    }

                }

            }

            $numReplaced = count($replaced);
            $numSkipped = count($skipped);
            $this->out->success("{$numReplaced} replaced, {$numSkipped} skipped");

        }
    }

    /**
     * @return string
     */
    protected function getOutputDir(): string
    {
        $arg = $this->in->hasArgument('output') ? 'output' : 'path';
        $dir = $this->in->getArgument($arg);
        if (str_ends_with($dir, '.txt')) {
            $dir = dirname($dir);
        }
        if (($arg == 'output') && !is_dir($dir)) {
            mkdir($dir);
        }
        return $dir;
    }

    /**
     * @param string $path
     * @return array
     */
    protected function getFiles(string $path): array
    {
        $files = [];
        if (is_dir($path)) {
            foreach (glob("{$path}/*.txt") as $file) {
                $files[] = $file;
            }
        } else {
            $files[] = $path;
        }
        return $files;
    }

    /**
     * @param string $content
     * @return array
     */
    protected function parseWords(string $content): array
    {
        return array_filter(preg_split('/\s+/', preg_replace('/[^[:alnum:]\s[:punct:]]/', '', $content)));
    }

}