<?php
// cleandocstore.php -- HotCRP maintenance script
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(CleanDocstore_Batch::make_args($argv)->run());
}

class CleanDocstore_Batch {
    /** @var Conf */
    public $conf;
    /** @var list<string> */
    public $docstores;
    /** @var int */
    public $mode = 0;
    /** @var ?int */
    public $count;
    /** @var ?float */
    public $min_usage;
    /** @var ?float */
    public $max_usage;
    /** @var ?int */
    public $bytes;
    /** @var bool */
    public $quiet;
    /** @var bool */
    public $verbose;
    /** @var bool */
    public $dry_run;
    /** @var bool */
    public $keep_temp;
    /** @var bool */
    public $only_temp;
    /** @var int */
    public $cutoff;
    /** @var DocumentHashMatcher */
    public $hash_matcher;
    /** @var list<?DocumentFileTree> */
    public $ftrees = [];

    /** @param list<string> $docstores */
    function __construct(Conf $conf, $docstores, $arg) {
        $this->conf = $conf;
        $this->docstores = $docstores;
        $this->mode = isset($arg["docstore"]) ? 1 : 0;
        $this->count = $arg["count"] ?? null;
        $this->min_usage = $arg["min-usage"] ?? null;
        $this->max_usage = $arg["max-usage"] ?? null;
        $this->quiet = isset($arg["quiet"]);
        $this->verbose = isset($arg["verbose"]);
        $this->dry_run = isset($arg["dry-run"]);
        $this->keep_temp = isset($arg["keep-temp"]);
        $this->only_temp = isset($arg["only-temp"]) || !$conf->s3_client();
        $this->cutoff = isset($arg["all"]) ? Conf::$now + 86400 : Conf::$now - 86400;
        $this->hash_matcher = new DocumentHashMatcher($arg["match"] ?? null);
    }

    /** @param DocumentFileTreeMatch $fm
     * @return bool */
    static function is_temp($fm) {
        return $fm->tree->treeid === 1;
    }

    /** @return ?DocumentFileTreeMatch */
    function fparts_random_match() {
        $fmatches = [];
        for ($i = 0; $i !== count($this->ftrees); ++$i) {
            if (!($ftree = $this->ftrees[$i])) {
                continue;
            }
            $n = 0;
            for ($j = 0;
                 $n < 5 && $j < ($n ? 10 : 10000) && !$ftree->is_empty();
                 ++$j) {
                $fm = $ftree->random_match();
                if ($fm->is_complete()
                    && (!self::is_temp($fm)
                        || max($fm->atime(), $fm->mtime()) < $this->cutoff)) {
                    ++$n;
                    $fmatches[] = $fm;
                } else {
                    $ftree->hide($fm);
                }
            }
            if ($n === 0) {
                array_splice($this->ftrees, $i, 1);
                --$i;
            }
        }
        usort($fmatches, function ($a, $b) {
            // week-old temporary files should be removed first
            $at = $a->atime();
            $bt = $b->atime();
            if ($at === false || $bt === false) {
                return $at ? -1 : ($bt ? 1 : 0);
            }
            $aage = Conf::$now - $at;
            if (self::is_temp($a)) {
                $aage = $aage > 604800 ? 100000000 : $aage * 2;
            }
            $bage = Conf::$now - $bt;
            if (self::is_temp($b)) {
                $bage = $bage > 604800 ? 100000000 : $bage * 2;
            }
            return $bage <=> $aage;
        });
        if (empty($fmatches)) {
            return null;
        } else {
            $fm = $fmatches[0];
            $fm->tree->hide($fm);
            return $fm;
        }
    }

    /** @param DocumentFileTreeMatch $fm
     * @return bool */
    private function check_match($fm) {
        $doc = DocumentInfo::make_hash($this->conf, $fm->algohash, Mimetype::type($fm->extension));
        $hashalg = $doc->hash_algorithm();
        if ($hashalg === false) {
            fwrite(STDERR, "{$fm->fname}: unknown hash\n");
            return false;
        }
        if (!$this->dry_run) {
            $chash = hash_file($hashalg, $fm->fname, true);
            if ($chash === false) {
                fwrite(STDERR, "{$fm->fname}: is unreadable\n");
                return false;
            } else if ($chash !== $doc->binary_hash_data()) {
                fwrite(STDERR, "{$fm->fname}: incorrect hash\n");
                fwrite(STDERR, "  data hash is " . $doc->hash_algorithm_prefix() . bin2hex($chash) . "\n");
                return false;
            }
        }
        if ($doc->check_s3()) {
            return true;
        } else {
            fwrite(STDERR, "{$fm->fname}: not on S3\n");
            return false;
        }
    }

    /** @return int */
    function run() {
        if ($this->mode === 1) {
            foreach ($this->docstores as $dp) {
                fwrite(STDOUT, "{$dp}\n");
            }
            return 0;
        }

        if (empty($this->docstores)) {
            throw new CommandLineException("No docstore");
        }

        preg_match('/\A((?:\/[^\/%]*(?=\/|\z))+)/', $this->docstores[0], $m);
        $usage_directory = $m[1];

        $usage_threshold = null;
        if ($this->min_usage !== null || $this->max_usage !== null) {
            $ts = disk_total_space($usage_directory);
            $fs = disk_free_space($usage_directory);
            if ($ts === false || $fs === false) {
                throw new CommandLineException("{$usage_directory}: Cannot evaluate free space");
            } else if ($fs >= $ts * (1 - ($this->max_usage ?? $this->min_usage))) {
                if (!$this->quiet) {
                    fwrite(STDOUT, "{$usage_directory}: free space sufficient\n");
                }
                return 0;
            }
            $want_fs = $ts * (1 - ($this->min_usage ?? $this->max_usage));
            $usage_threshold = $want_fs - $fs;
            $count = $this->count ?? 5000;
        } else {
            $usage_threshold = null;
            $count = $this->count ?? 10;
        }

        foreach ($this->docstores as $i => $dp) {
            if (!str_starts_with($dp, "/") || strpos($dp, "%") === false) {
                throw new CommandLineException("{$dp}: Bad docstore pattern");
            }
            if (!$this->only_temp) {
                $this->ftrees[] = new DocumentFileTree($dp, $this->hash_matcher, 0);
            }
            if (!$this->keep_temp) {
                $ds = Docstore::make($dp);
                $this->ftrees[] = new DocumentFileTree($ds->root_pattern() . "tmp/%w", $this->hash_matcher, 1);
            }
        }

        // actual run
        $ndone = $nsuccess = $bytesremoved = 0;
        while ($count > 0
               && ($usage_threshold === null || $bytesremoved < $usage_threshold)
               && ($fm = $this->fparts_random_match())) {
            if (self::is_temp($fm) || $this->check_match($fm)) {
                $size = filesize($fm->fname);
                if ($this->dry_run || unlink($fm->fname)) {
                    if ($this->verbose) {
                        fwrite(STDOUT, "{$fm->fname}: " . ($this->dry_run ? "would remove\n" : "removed\n"));
                    }
                    ++$nsuccess;
                    $bytesremoved += $size;
                } else {
                    fwrite(STDERR, "{$fm->fname}: cannot remove\n");
                }
            }
            --$count;
            ++$ndone;
        }

        if (!$this->quiet) {
            fwrite(STDOUT, $usage_directory . ": " . ($this->dry_run ? "would remove " : "removed ") . plural($nsuccess, "file") . ", " . plural($bytesremoved, "byte") . "\n");
        }
        if ($nsuccess === 0 && !$this->quiet) {
            fwrite(STDERR, "Nothing to clean\n");
        }
        return $nsuccess > 0 && $nsuccess === $ndone ? 0 : 1;
    }

    /** @return CleanDocstore_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "count:,c: {n} =COUNT Clean up to COUNT files",
            "match:,m: =MATCH Clean files matching MATCH",
            "dry-run,d Do not remove files",
            "max-usage:,u: {f} =FRAC Clean until usage is below FRAC",
            "min-usage:,U: {f} =FRAC Do not clean if usage is below FRAC",
            "all Clean all files, including files recently modified",
            "quiet,silent,q Be quiet",
            "keep-temp Keep temporary files",
            "only-temp Only clean temporary files",
            "help,h",
            "verbose,V Be more verbose",
            "docstore Output docstore patterns and exit"
        )->helpopt("help")
         ->description("Remove old files from HotCRP docstore
Usage: php batch/cleandocstore.php [-c COUNT|-u FRAC] [-V] [-d] [DOCSTORES...]\n")
         ->parse($argv);

        if (isset($arg["max-usage"]) && ($arg["max-usage"] < 0 || $arg["max-usage"] > 1)) {
            throw new CommandLineException("`--max-usage` must be between 0 and 1");
        }
        if (isset($arg["min-usage"]) && ($arg["min-usage"] < 0 || $arg["min-usage"] > 1)) {
            throw new CommandLineException("`--min-usage` must be between 0 and 1");
        }
        if (($arg["max-usage"] ?? 1) < ($arg["min-usage"] ?? 0)) {
            throw new CommandLineException("`--max-usage` cannot be less than `--min-usage`");
        }

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        $confds = $conf->docstore();
        $confdps = [];
        if (empty($arg["_"])) {
            if ($confds) {
                $confdps[] = $confds->full_pattern();
            }
        } else {
            foreach ($arg["_"] as $confdp) {
                if ($confdp === "-" || $confdp === "default") {
                    if (!$confds) {
                        throw new CommandLineException("No default docstore");
                    }
                    $confdps[] = $confds->full_pattern();
                } else {
                    $confdps[] = $confdp;
                }
            }
        }
        return new CleanDocstore_Batch($conf, $confdps, $arg);
    }
}
