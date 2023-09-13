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

    const PUNCT_TRIM = '.,"\'-;:*';
    protected InputInterface $in;
    protected SymfonyStyle $out;
    protected SQLite3 $db;
    protected Dictionary $dict;

    protected function execute(InputInterface $in, OutputInterface $out): int
    {

        $this->setIO($in, $out);

        $this->initDb();
        $this->initDict();

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
            ->addOption('db-file', 'd', InputOption::VALUE_OPTIONAL, 'SQLite database file', './corrections.db')
            ->addOption('lang', 'l', InputOption::VALUE_OPTIONAL, 'Language', 'en')
            ->addOption('dict-threshold', 't', InputOption::VALUE_OPTIONAL, 'Threshold to add correction to dictionary', 2)
        ;
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
                `source` TEXT
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

        $config = pspell_config_create($this->in->getOption('lang'));
        pspell_config_mode($config, PSPELL_FAST);
        $this->dict = pspell_new_config($config);

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
                pspell_add_to_session($this->dict, $word);
            }
        }

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
            if (!in_array($suggestion, $suggestions)) {
                $suggestions[] = $suggestion;
            }
        }

        return $suggestions;

    }

    /**
     * @param bool $train
     * @return void
     */
    protected function processFiles(bool $train = false): void
    {

        $files = $this->getFiles($this->in->getArgument('path'));
        foreach ($files as $file)
        {

            $file = realpath($file);
            $this->out->title($file);

            $content = file_get_contents($file);
            $this->out->write($content);
            $words = $this->parseWords($content);
            $numWords = count($words);
            $errors = [];
            $replacements = [];

            for ($i = 1; $i < $numWords; ++$i) {

                $word = trim($words[$i], static::PUNCT_TRIM);
                if (
                    !preg_match('/([a-zA-Z][\.])+/', $word) && // Don't flag acronyms
                    !filter_var($word, FILTER_VALIDATE_URL) && // Don't flag URLs
                    !pspell_check($this->dict, $word)
                ) {

                    $this->out->section($word);

                    $context = '';
                    for ($n = $i-5; $n < $i; ++$n)
                    {
                        if (isset($words[$n])) {
                            $context .= "{$words[$n]} ";
                        }
                    }
                    $context .= str_replace($word, "<fg=white;bg=red>{$word}</>", $words[$i]);
                    for ($n = $i+1; $n < $i+6; ++$n)
                    {
                        if (isset($words[$n])) {
                            $context .= " {$words[$n]}";
                        }
                    }
                    $this->out->write($context);

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
                        $pos = strpos($content, $error);
                        if ($pos !== false) {
                            $content = substr_replace($content, $correction, $pos, strlen($error));
                        }

                        if (file_put_contents($file, $content)) {
                            if (empty($correction)) {
                                $this->out->text("Removed <fg=red>{$error}</>");
                            } else {
                                $this->out->text("Replaced <fg=red>{$error}</> with <fg=green>{$correction}</>");
                            }
                        } else {
                            $this->out->error("Error writing to {$file}");
                        }
                    }

                }

            }

        }
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
        return array_filter(preg_split('/[[:blank:]]+/', preg_replace('/[^[:alpha:][:blank:][:punct:]]/', '', $content)));
    }

}