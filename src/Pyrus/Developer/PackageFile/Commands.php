<?php
namespace Pyrus\Developer\PackageFile;

use PharData;
use Pyrus\Config;
use Pyrus\Developer\CoverageAnalyzer as Coverage;
use Pyrus\Developer\PackageFile\Commands\GeneratePEAR2;
use Pyrus\Developer\PackageFile\Commands\MakePEAR2;
use Pyrus\Developer\PackageFile\Commands\MakePECL;
use Pyrus\Developer\Runphpt\Runner;
use Pyrus\Main;
use Pyrus\Package;
use Pyrus\Package\Cloner;
use Pyrus\Package\Creator;
use Pyrus\PackageFileInterface;
use Pyrus\PackageInterface;

class Commands
{
    protected $header;
    protected $footer;
    protected $skeleton;

    public function getDirectory($args)
    {
        if (isset($args['dir'])) {
            $dir = $args['dir'];
            if (!file_exists($dir)) {
                throw new Exception(
                    'Invalid directory: ' . $dir . ' does not exist'
                );
            }
        } else {
            $dir = getcwd();
        }
        return $dir;
    }

    public function makePackageXml($frontend, $args, $options)
    {
        $dir = $this->getDirectory($args);
        if (!isset($args['packagename'])
            && file_exists($dir . '/package.xml')
        ) {
            try {
                $testpackage = new Package($dir . '/package.xml');
                $args['packagename'] = $testpackage->name;
                // if packagename isn't set, channel can't be set
                $args['channel'] = $testpackage->channel;
            } catch (\Exception $e) {
                // won't work, user has to be explicit
                throw new \Pyrus\Developer\Creator\Exception(
                    'missing first argument: PackageName'
                );
            }
        }
        if (!isset($args['channel'])) {
            $args['channel'] = 'pear2.php.net';
        } else {
            $args['channel'] = Config::current()->channelregistry
                ->channelFromAlias($args['channel']);
        }
        if (!isset($options['scanoptions'])
            && file_exists($dir . '/scanoptions.php')
        ) {
            $options['scanoptions'] = 'scanoptions.php';
        }
        $scanoptions = array();
        if (isset($options['scanoptions'])) {
            $file = $options['scanoptions'];
            $path = $dir;
            $getscanoptions = function () use ($path, $file) {
                $scanoptions = array();
                include $path . '/' . $file;
                return $scanoptions;
            };
            $scanoptions = $getscanoptions();
        }
        echo "Creating package.xml...";
        $makePear2 = new MakePEAR2(
            $dir,
            $args['packagename'],
            $args['channel'],
            false,
            true,
            !$options['nocompatible'],
            $scanoptions
        );
        if (!isset($options['packagexmlsetup'])
            && file_exists($makePear2->path . '/packagexmlsetup.php')
        ) {
            $options['packagexmlsetup'] = 'packagexmlsetup.php';
        }
        if ($options['packagexmlsetup']) {
            $package = $makePear2->packagefile;
            // compatible is null if not specified
            $compatible = $makePear2->compatiblepackagefile;
            $file = $options['packagexmlsetup'];
            $path = $makePear2->path;
            if (!file_exists($path . '/' . $file)) {
                throw new \Pyrus\Developer\Creator\Exception(
                    'packagexmlsetup file must be in a subdirectory ' .
                    'of the package.xml'
                );
            }
            $getinfo = function () use ($file, $path, $package, $compatible) {
                include $path . '/' . $file;
            };
            $getinfo();
            $makePear2->save();
        }
        echo "done\n";
        if (isset($options['package']) && $options['package']) {
            $formats = explode(',', $options['package']);
            $first = $formats[0];
            $formats = array_flip($formats);
            $formats[$first] = 1;

            $opts = array(
                'phar' => false,
                'tgz' => false,
                'tar' => false,
                'zip' => false
            );
            $opts = array_merge($opts, $formats);
            $opts['stub'] = $options['stub'];
            $opts['extrasetup'] = $options['extrasetup'];
            if (isset($args['dir'])) {
                $args = array('packagexml' => $args['dir'] . '/package.xml');
            } else {
                $args = array();
            }
            $this->package($frontend, $args, $opts);
        }
    }

    public function makePECLPackage($frontend, $args, $options)
    {
        $dir = $this->getDirectory($args);
        $sourceextensions = array(
            'c',
            'cc',
            'h',
            'm4',
            'w32',
            're',
            'y',
            'l',
            'frag'
        );
        if (isset($args['extension'])) {
            $sourceextensions = array_merge($sourceextensions, $args['extension']);
        }
        if (!isset($args['packagename']) && file_exists($dir . '/package.xml')) {
            try {
                $testpackage = new Package($dir . '/package.xml');
                $args['packagename'] = $testpackage->name;
                // if packagename isn't set, channel can't be set
                $args['channel'] = $testpackage->channel;
            } catch (\Exception $e) {
                // won't work, user has to be explicit
                throw new \Pyrus\Developer\Creator\Exception(
                    'missing first argument: PackageName'
                );
            }
        }
        if (!isset($args['channel'])) {
            $args['channel'] = 'pecl.php.net';
        } else {
            $args['channel'] = Config::current()->channelregistry
                ->channelFromAlias($args['channel']);
        }
        echo "Creating package.xml...";
        $package = new MakePECL(
            $dir,
            $args['packagename'],
            $args['channel'],
            $sourceextensions
        );
        echo "done\n";
        if ($options['donotpackage']) {
            return;
        }
        if (extension_loaded('zlib')) {
            echo "Creating ",
                $package->name . '-' . $package->version['release'] .
                '.tgz ...';
            if (file_exists(
                $dir . '/' . $package->name . '-' . $package->version['release']
                . '.tgz'
            )
            ) {
                unlink(
                    $dir . '/' . $package->name . '-' .
                    $package->version['release'] . '.tgz'
                );
            }
            $phar = new PharData(
                $dir . '/' . $package->name . '-' . $package->version['release']
                . '.tgz'
            );
        } else {
            echo "Creating ",
                $package->name . '-' . $package->version['release'] .
                '.tar ...';
            if (file_exists(
                $dir . '/' . $package->name . '-' . $package->version['release']
                . '.tar'
            )
            ) {
                unlink(
                    $dir . '/' . $package->name . '-' .
                    $package->version['release'] . '.tar'
                );
            }
            $phar = new PharData(
                $dir . '/' . $package->name . '-' . $package->version['release']
                . '.tar'
            );
        }
        // add md5sum
        foreach ($package->files as $path => $file) {
            $stuff = $file->getArrayCopy();
            $stuff['attribs']['md5sum'] = md5_file(
                $dir . '/' . $file['attribs']['name']
            );
            $package->files[$path] = $stuff;
        }
        $phar['package.xml'] = (string) $package;
        foreach ($package->files as $file) {
            // do automatic package-time version replacement
            $phar[$file['attribs']['name']] = strtr(
                file_get_contents($dir . '/' . $file['attribs']['name']),
                array(
                    '@PACKAGE_VERSION' . '@' => $package->version['release'],
                    '@PACKAGE_NAME' . '@' => $package->name,
                )
            );
        }
        echo "done\n";
    }

    /** @todo Consider simply injecting the Package object as appropriate */
    public function package($frontend, $args, $options)
    {
        $path = getcwd() . DIRECTORY_SEPARATOR;
        $package = new Package(null);


        if (!isset($args['packagexml'])
            && !file_exists($path . 'package.xml')
            && !file_exists($path . 'package2.xml')
        ) {
            throw new \Pyrus\PackageFile\Exception(
                "No package.xml or package2.xml found in " . $path
            );
        }

        if (isset($args['packagexml'])) {
            $package = new Package($args['packagexml']);
        } else {
            // first try ./package.xml
            if (file_exists($path . 'package.xml')) {
                try {
                    $package = new Package($path . 'package.xml');
                } catch (\Pyrus\PackageFile\Exception $e) {
                    if ($e->getCode() != -3) {
                        throw $e;
                    }

                    if (!file_exists($path . 'package2.xml')) {
                        throw $e;
                    }

                    $package = new Package($path . 'package2.xml');
                    // now the creator knows to do the magic of
                    // package2.xml/package.xml
                    $package->thisIsOldAndCrustyCompatible();
                }
            }

            // Alternatively; there's only a package2.xml
            if (file_exists($path . 'package2.xml')
                && !file_exists($path . 'package.xml')
            ) {
                $package = new Package($path . 'package2.xml');
            }
        }

        if ($package->isNewPackage()) {
            if (!$options['phar']
                && !$options['zip']
                && !$options['tar']
                && !$options['tgz']
            ) {
                // try tgz first
                if (extension_loaded('zlib')) {
                    $options['tgz'] = true;
                } else {
                    $options['tar'] = true;
                }
            }
            if ($options['phar'] && ini_get('phar.readonly')) {
                throw new \Pyrus\Developer\Creator\Exception(
                    "Cannot create phar archive, pass -dphar.readonly=0"
                );
            }
        } else {
            if ($options['zip']
                || $options['phar']
            ) {
                echo "Zip and Phar archives can only be created " .
                    "for PEAR2 packages, ignoring\n";
            }
        }

        // get openssl cert if set, and password
        if (Config::current()->openssl_cert) {
            if ('yes' == $frontend->ask(
                'Sign package?',
                array('yes', 'no'),
                'yes'
            )
            ) {
                $cert = Config::current()->openssl_cert;
                if (!file_exists($cert)) {
                    throw new \Pyrus\Developer\Creator\Exception(
                        'OpenSSL certificate ' . $cert . ' does not exist'
                    );
                }
                $releaser = Config::current()->handle;
                $maintainers = array();
                foreach ($package->maintainer as $maintainer) {
                    $maintainers[] = $maintainer->user;
                }
                if (!strlen($releaser)) {
                    throw new \Pyrus\Developer\Creator\Exception(
                        'handle configuration variable must be from ' .
                        'package.xml (one of ' .
                        implode(', ', $maintainers) .
                        ')'
                    );
                }
                if (!in_array($releaser, $maintainers)) {
                    throw new \Pyrus\Developer\Creator\Exception(
                        'handle configuration variable must be from ' .
                        'package.xml (one of ' .
                        implode(', ', $maintainers) .
                        ')'
                    );
                }
                $passphrase = $frontend->ask(
                    'passphrase for OpenSSL PKCS#12 certificate?'
                );
                if (!$passphrase) {
                    $passphrase = '';
                }
            } else {
                $releaser = $cert = null;
                $passphrase = '';
            }
        } else {
            $releaser = $cert = null;
            $passphrase = '';
        }

        $sourcepath = Main::getSourcePath();
        if (0 !== strpos($sourcepath, 'phar://')) {
            // running from svn, assume we're in a standard package layout
            // with a vendor dir
            // TODO: Improve this to automatically find latest releases
            // from pear2.php.net
            $exceptionpath = $autoloadpath = $multierrorspath = realpath(
                $sourcepath . '/../vendor/php'
            ) . '/PEAR2';
            if (!file_exists($exceptionpath . '/Exception.php')) {
                throw new \Pyrus\Developer\Creator\Exception(
                    'Cannot locate PEAR2/Exception in a local vendor/ dir. '
                    . 'It is best to install the latest versions of these locally.'
                );
            }
        } else {
            $exceptionpath = $autoloadpath = $multierrorspath = $sourcepath .
                '/PEAR2';
        }
        $extras = array();
        $stub = false;
        if ($options['phar']) {
            if (isset($mainfile)) {
                $extras[] = array('phar', \Phar::PHAR, \Phar::GZ);
            } else {
                $mainfile = $package->name . '-' . $package->version['release']
                    . '.phar';
                $mainformat = \Phar::PHAR;
                $maincompress = \Phar::NONE;
            }
            if (!$options['stub']
                && file_exists(dirname($package->archivefile) . '/stub.php')
            ) {
                $stub = file_get_contents(
                    dirname($package->archivefile) . '/stub.php'
                );
            } elseif ($options['stub']
                && file_exists($options['stub'])
            ) {
                $stub = file_get_contents($options['stub']);
            }
            $stub = strtr(
                $stub,
                array(
                    '@PACKAGE_VERSION' . '@' => $package->version['release'],
                    '@PACKAGE_NAME' . '@' => $package->name,
                )
            );
        }
        if ($options['tar']) {
            if (isset($mainfile)) {
                $extras[] = array('tar', \Phar::TAR, \Phar::NONE);
            } else {
                $mainfile = $package->name . '-' . $package->version['release']
                    . '.tar';
                $mainformat = \Phar::TAR;
                $maincompress = \Phar::NONE;
            }
        }
        if ($options['tgz'] && extension_loaded('zlib')) {
            if (isset($mainfile)) {
                $extras[] = array('tgz', \Phar::TAR, \Phar::GZ);
            } else {
                $mainfile = $package->name . '-' . $package->version['release']
                    . '.tgz';
                $mainformat = \Phar::TAR;
                $maincompress = \Phar::GZ;
            }
        } elseif ($options['tgz']) {
            $options['tar'] = true;
        }
        if ($options['zip']) {
            if (isset($mainfile)) {
                $extras[] = array('zip', \Phar::ZIP, \Phar::NONE);
            } else {
                $mainfile = $package->name . '-' . $package->version['release']
                    . '.zip';
                $mainformat = \Phar::ZIP;
                $maincompress = \Phar::NONE;
            }
        }
        if (isset($options['outputfile'])) {
            $mainfile = $options['outputfile'];
        }
        echo "Creating ", $mainfile, "\n";
        if (null == $cert) {
            $clone = $extras;
            $extras = array();
        } else {
            foreach ($extras as $stuff) {
                echo "Creating ",
                    $package->name,
                    '-',
                    $package->version['release'],
                    '.',
                    $stuff[0],
                    "\n";
            }
            $clone = array();
        }
        $creator = new Creator(
            array(
                new \Pyrus\Developer\Creator\Phar(
                    $mainfile,
                    $stub,
                    $mainformat,
                    $maincompress,
                    $extras,
                    $releaser,
                    $package,
                    $cert,
                    $passphrase
                )
            ),
            $exceptionpath,
            $autoloadpath,
            $multierrorspath
        );
        if (!$options['extrasetup']
            && file_exists(dirname($package->archivefile) . '/extrasetup.php')
        ) {
            $options['extrasetup'] = 'extrasetup.php';
        }
        if ($options['extrasetup']) {
            // encapsulate the extrafiles inside a closure
            // so there is no access to the variables in this function
            $getinfo = function () use ($options, $package) {
                $file = $options['extrasetup'];
                if (!file_exists(dirname($package->archivefile) . '/' . $file)) {
                    throw new \Pyrus\Developer\Creator\Exception(
                        'extrasetup file must be in the same directory ' .
                        'as package.xml'
                    );
                }
                include dirname($package->archivefile) . '/' . $file;
                if (!isset($extrafiles)) {
                    throw new \Pyrus\Developer\Creator\Exception(
                        'extrasetup file must set $extrafiles variable ' .
                        'to an array of files'
                    );
                }
                if (!is_array($extrafiles)) {
                    throw new \Pyrus\Developer\Creator\Exception(
                        'extrasetup file must set $extrafiles variable ' .
                        'to an array of files'
                    );
                }
                foreach ($extrafiles as $path => $file) {
                    if (is_object($file)) {
                        if ($file instanceof PackageInterface
                            || $file instanceof PackageFileInterface
                        ) {
                            continue;
                        }
                        throw new \Pyrus\Developer\Creator\Exception(
                            'extrasetup file object must implement ' .
                            '\Pyrus\PackageInterface' .
                            ' or \Pyrus\PackageFileInterface'
                        );
                    }
                    if (!file_exists($file)) {
                        throw new \Pyrus\Developer\Creator\Exception(
                            'extrasetup file ' . $file . ' does not exist'
                        );
                    }
                    if (!is_string($path)) {
                        throw new \Pyrus\Developer\Creator\Exception(
                            'extrasetup file ' . $file .
                            ' index should be the path to save in the release'
                        );
                    }
                }
                return $extrafiles;
            };
            $extrafiles = $getinfo();
        } else {
            $extrafiles = array();
        }
        $creator->render($package, $extrafiles);
        if (count($clone)) {
            $cloner = new Cloner($mainfile);
            foreach ($clone as $extra) {
                echo "Creating ",
                    $package->name,
                    '-',
                    $package->version['release'],
                    '.',
                    $extra[0],
                    "\n";
                $cloner->{'to' . $extra[0]}();
            }
        }
        echo "done\n";
    }

    public function runTests($frontend, $args, $options)
    {
        if ($options['modified']) {
            if (!isset($args['path']) || !count($args['path'])) {
                $testpath = realpath(getcwd() . '/tests');
                $codepath = realpath(getcwd() . '/src');
            } else {
                $testpath = realpath($args['path'][0]);
                $codepath = realpath($args['path'][1]);
            }
            $sqlite = new Coverage\Sqlite(
                $testpath . '/pear2coverage.db',
                $codepath,
                $testpath
            );
            $modified = $sqlite->getModifiedTests();
            if (!count($modified)) {
                goto dorender;
            }
        }

        if ($options['modified']) {
            $options['recursive'] = false;
            $options['coverage'] = true;
        } else {
            $modified = $args['path'];
        }
        $runner = new Runner($options['coverage'], $options['recursive']);

        try {
            if (!$runner->runTests($modified)) {
                if ($options['modified']) {
                    echo "Tests failed - not regenerating coverage data\n";
                }
                return false;
            }
        } catch (\Exception $e) {
            // tests failed
            if ($options['modified']) {
                echo "Tests failed - not regenerating coverage data\n";
                return false;
            } else {
                throw $e;
            }
        }
        if (!$options['modified']) {
            return true;
        }
dorender:
        $a = new Coverage\Aggregator(
            $testpath,
            $codepath,
            $testpath . '/pear2coverage.db'
        );
        $coverage = $a->retrieveProjectCoverage();
        if ($coverage[1]) {
            echo "Project coverage: ",
                (($coverage[0] / $coverage[1]) * 100),
                "%\n";
        } else {
            echo "Unknown coverage.\n";
        }
    }

    /**
     * Create the PEAR2 skeleton
     *
     * @param mixed $frontend \Pyrus\ScriptFrontend\Commands
     * @param array $args
     * @param array $options
     *
     * @return void
     *
     * @uses Pyrus\Developer\PackageFile\Commands\PEAR2Skeleton
     * @uses self::makePackageXml()
     */
    public function pear2Skeleton($frontend, array $args, array $options)
    {
        if (!isset($args['channel'])) {
            $args['channel'] = 'pear2.php.net';
        } else {
            $args['channel'] = Config::current()->channelregistry
                ->channelFromAlias($args['channel']);
        }

        $info = $this->parsePackageName($args['package'], $args['channel']);

        $skeleton = new GeneratePEAR2($info);
        $skeleton->generate();

        $options['package']         = false;
        $options['nocompatible']    = false;
        
        $this->makePackageXml(
            $frontend,
            array(
                'packagename' => $info['__PACKAGE__'],
                'channel' => $args['channel'],
                'dir' => getcwd() . DIRECTORY_SEPARATOR . $info['__PACKAGE__']
            ),
            $options
        );
    }

    /**
     * Parse the package name and channel.
     * 
     * Returns an array with:
     * - \_\_MAIN_NAMESPACE\_\_
     * - \_\_MAIN_CLASS\_\_
     * - \_\_MAIN_PATH\_\_
     * - \_\_PACKAGE\_\_
     * - \_\_PATH\_\_
     * - \_\_REPO\_\_
     *
     * @param string $package E.g. PEAR2_Foo_Bar
     * @param string $channel E.g. pear2.php.net
     *
     * @return array
     * @see    GeneratePEAR2
     */
    public static function parsePackageName($package, $channel)
    {
        $ret = array(
            '__CATEGORY__' => 'default'
        );
        $package = explode('_', $package);
        if ($channel == 'pear2.php.net') {
            if ($package[0] != 'PEAR2') {
                if ($package[0] == 'pear2' || $package[0] == 'Pear2') {
                    $package[0] = 'PEAR2';
                } else {
                    array_unshift($package, 'PEAR2');
                }
                $ret['__PACAKGE__'] = implode('_', $package);
            }
            $package[0] = 'PEAR2';
            $path = $package;
            array_shift($path);

            $ret['__REPO__'] = 'http://github.com/pear2/' .
                implode('_', $package);
            if (count($package) > 2) {
                $ret['__CATEGORY__'] = $package[1];
            }
        } else {
            $ret['__REPO__']           = 'http://' . $channel . '/' .
                implode('_', $package);
        }

        $ret['__YEAR__']           = date('Y');
        $ret['__PATH__']           = implode('_', $package);
        $ret['__PACKAGE__']        = implode('_', $package);
        $ret['__MAIN_PATH__']      = implode('/', $package);
        $ret['__MAIN_CLASS__']     = array_pop($package);
        $ret['__MAIN_NAMESPACE__'] = implode('\\', $package);

        ksort($ret);
        return $ret;
    }

    public function extSkeleton($frontend, $args, $options)
    {
        if (file_exists($args['extension'])) {
            throw new \Pyrus\Developer\Creator\Exception(
                'Extension ' . $args['extension'] . ' directory already exists'
            );
        }
        $protos = array();
        if ($options['proto']) {
            $protos = $this->parseProtos($options['proto']);
        }

        $ext = $args['extension'];
        mkdir($ext);
        mkdir($ext . '/tests');

        $this->skeleton = realpath(
            __DIR__ .
            '/../../../../data/pyrus.net/Pyrus_Developer/extSkeleton'
        );
        $this->footer = "\n" .
        "/*\n" .
        " * Local variables:\n" .
        " * tab-width: 4\n" .
        " * c-basic-offset: 4\n" .
        " * End:\n" .
        " * vim600: noet sw=4 ts=4 fdm=marker\n" .
        " * vim<600: noet sw=4 ts=4\n" .
        " */";

        $this->header = str_replace(
            "\r\n",
            "\n",
            "/*
  +----------------------------------------------------------------------+
  | PHP Version 6                                                        |
  +----------------------------------------------------------------------+
  | Copyright (c) 1997-" . date('Y') . " The PHP Group               |
  +----------------------------------------------------------------------+
  | This source file is subject to version 3.01 of the PHP license,      |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | http://www.php.net/license/3_01.txt                                  |
  | If you did not receive a copy of the PHP license and are unable to   |
  | obtain it through the world-wide-web, please send a note to          |
  | license@php.net so we can mail you a copy immediately.               |
  +----------------------------------------------------------------------+
  | Author:                                                              |
  +----------------------------------------------------------------------+
*/

/* \$Id: header 263616 2008-07-26 22:21:12Z jani $ */
"
        );
        $this->saveConfigM4($ext);
        $this->saveConfigW32($ext);

        $replace = $this->postProcessProtos($options, $ext, $protos);
        foreach (array(
            'skeleton.c',
            'skeleton.php',
            'php_skeleton.h',
            'CREDITS',
            'EXPERIMENTAL',
            'tests/001.phpt'
        ) as $file
        ) {
            $dest = str_replace('skeleton', $ext, $file);
            $this->processFile($ext, $file, $dest, $replace);
        }
        file_put_contents(
            $ext . '/README',
            'Extension package ' . $ext . " summary\n\n" .
            'Detailed description (edit README to change this)'
        );
        file_put_contents(
            $ext . '/CREDITS',
            ";; put your info here\n" .
            'Your Name [handle] <handle@php.net> (lead)'
        );
        file_put_contents(
            $ext . '/RELEASE-0.1.0',
            'Package ' . $ext . " release notes for version 0.1.0."
        );
        file_put_contents(
            $ext . '/API-0.1.0',
            'Package ' . $ext . " API release notes for version 0.1.0."
        );
        $this->makePECLPackage(
            $frontend,
            array(
                'packagename' => $ext,
                'channel' => 'pecl.php.net',
                'dir' => realpath($ext)
            ),
            array('donotpackage' => true)
        );
        echo <<<eof

To use your new PECL extension, you will have to execute the following steps:

1.  $ cd $ext
2.  $ edit README, CREDITS, RELEASE-0.1.0
3.  $ edit config.m4 and config.w32
4.  $ phpize
5.  $ ./configure
6.  $ make
7.  $ php -n -dextension_dir=`pwd`/modules -dextension=$ext.so $ext.php
8.  $ edit $ext.c to correct errors
9.  $ make

Repeat steps 4-8 until you are satisfied with ext/$ext/config.m4 and
step 7 confirms that your module is compiled into PHP. Then, start writing
code and repeat the last two steps as often as necessary.

Once you are satisfied, run phpize --clean to remove temporary files.

If this extension is part of PHP rather than a PECL extension, run these steps
instead:

1.  $ cd ..
2.  $ edit ext/$ext/config.m4 and ext/$ext/config.w32
3.  $ ./buildconf
4.  $ ./configure --[with|enable]-$ext
5.  $ make
6.  $ ./php -n -f ext/$ext/$ext.php
7.  $ edit ext/$ext/$ext.c
8.  $ make

Repeat steps 3-6 until you are satisfied with ext/$ext/config.m4 and
step 6 confirms that your module is compiled into PHP. Then, start writing
code and repeat the last two steps as often as necessary.

eof;

    }

    public function postProcessProtos($options, $ext, $protos)
    {
        if (!count($protos)) {
            return array(
                    '/* __header_here__ */'                => $this->header,
                    '/* __footer_here__ */'                => $this->footer,
                    'extname'                              => $ext,
                    'EXTNAME'                              => strtoupper($ext),
                   );
        }
        $funcdefs = $methoddefs = $arginfo = $functions = $methods = $classdef
            = $globals = $funcdecl = '';
        $funcinfo = $classinfo = array();

        foreach ($protos as $proto) {
            list($funcinfo, $classinfo) = $this->getFunctionFromProto(
                $ext,
                $options,
                $proto,
                $funcinfo,
                $classinfo
            );
        }
        list($header, $globals, $classdef, $methoddefs, $methoddecls)
            = $this->getClassDefinition($classinfo, $ext);
        foreach ($funcinfo as $function) {
            $funcdecl .= $function['headerdeclare'];
            $functions .= $function['definition'];
            $funcdefs .= $function['declaration'];
            $arginfo .= $function['arginfo'] . "\n";
        }
        foreach ($classinfo as $class => $info) {
            $methods .= "/* class $class methods */\n";
            foreach ($info as $function) {
                $methods .= $function['definition'];
                $arginfo .= $function['arginfo'] . "\n";
            }
        }

        return array("\t/* __function_entries_here__ */\n" => $funcdefs,
                     "/* {{{ extname_module_entry\n"
                        => $methods . $methoddefs .
                            "/* {{{ extname_module_entry\n",
                    "/* __function_stubs_here__ */\n"      => $functions,
                    "PHP_MINIT_FUNCTION(extname)\n{\n"
                        => "PHP_MINIT_FUNCTION(extname)\n{\n" . $classdef,
                    "/* True global resources - no need for thread safety here */\n"
                        => "/* True global resources - no " .
                            "need for thread safety here */\n" .
                            $arginfo . $globals,
                    "/* __function_declarations_here__ */" => $header . $funcdecl,
                    '/* __header_here__ */'                => $this->header,
                    '/* __footer_here__ */'                => $this->footer,
                    'extname'                              => $ext,
                    'EXTNAME'                              => strtoupper($ext),
               );
    }

    public function processFile($ext, $source, $dest, $replace)
    {
        $filename = $this->skeleton . '/' . $source;
        if (!file_exists($filename)) {
            return false; // FIXME proper handling
        }

        $s = file_get_contents($filename);
        file_put_contents(
            $ext . '/' . $dest,
            str_replace(array_keys($replace), array_values($replace), $s)
        );
    }

    public function saveConfigM4($ext)
    {
        $filename = $this->skeleton . '/config.m4';
        if (!file_exists($filename)) {
            return false; // FIXME proper handling
        }

        $m4 = file_get_contents($filename);
        file_put_contents(
            $ext . '/config.m4',
            str_replace(
                array('@EXTNAME@', '@extname@'),
                array(strtoupper($ext), $ext),
                $m4
            )
        );
    }

    public function saveConfigW32($ext)
    {
        $filename = $this->skeleton . '/config.w32';
        if (!file_exists($filename)) {
            return false; // FIXME proper handling
        }

        $w32 = file_get_contents($filename);
        file_put_contents(
            $ext . '/config.w32',
            str_replace(
                array('@EXTNAME@', '@extname@'),
                array(strtoupper($ext), $ext),
                $w32
            )
        );
    }

    public function getClassDefinition($classinfo, $extension)
    {
        $decl = "\tzend_class_entry ce;\n";
        $methoddecls = $methoddefs = $globals = $header = '';
        foreach ($classinfo as $class => $methods) {
            $lowerclass = strtolower($class);
            $header .= 'typedef struct _' .
                $extension . '_' . $lowerclass . " {\n} " .
                $extension . '_' . $lowerclass . ";\n";
            $globals .= "PHP_" . strtoupper($extension) . "_API zend_class_entry *" .
                        $extension . "_ce_" . $class . ";\n";
            $decl .= "\tINIT_CLASS_ENTRY(ce, \"" .
                $class . "\", " . $lowerclass . "_methods);\n\t" .
                $extension . "_ce_" . $class .
                " = zend_register_internal_class_ex(&ce, " .
                "\n\t\t\tNULL, /* change this to the zend_class_entry * for the parent class, if any */\n\t\t\tNULL  TSRMLS_CC);\n";
            $methoddefs .= "\nzend_function_entry " .
                $lowerclass . "_methods[] = {\n";
            foreach ($methods as $method) {
                $methoddecls .= $method['forwarddecl'];
                $methoddefs .= $method['declaration'];
            }
            $methoddefs .= "\t{NULL, NULL, NULL}\n};\n";
        }
        $header = "\n" . $header . "\n";
        return array($header, $globals, $decl, $methoddefs, $methoddecls);
    }

    public function getFunctionFromProto(
        $ext,
        $options,
        $proto,
        $funcinfo = array(),
        $classinfo = array()
    ) {
        $types = $resources = '';
        $argshort = '';
        $arglong = '';
        $hadoptional = false;

        if ($proto['class']) {
            $arginfo = 'ZEND_BEGIN_ARG_INFO_EX(arginfo_' .
                $proto['class'] . '_' . $proto['function'] . ', 0, 0, ';
        } else {
            $arginfo = 'ZEND_BEGIN_ARG_INFO_EX(arginfo_' .
                $proto['function']. ', 0, 0, ';
        }
        $required = 0;
        $argopts = '';
        foreach ($proto['args'] as $arg) {
            if ($arg['optional'] && !$hadoptional) {
                $argshort .= '|';
                $hadoptional = true;
            } elseif (!$hadoptional) {
                $required++;
            }
            $argshort .= $arg['code'];

            $arglong .= ', &' . $arg['name'];
            if ($arg['type'] == "...") {
                $argopts .= "\tZEND_ARG_INFO(0, " . $arg['name'] . "...)\n";
            } else {
                $argopts .= "\tZEND_ARG_INFO(0, " . $arg['name'] . ")\n";
            }

            if ($arg['type'] == "int" || $arg['type'] == "long") {
                $types .= "\tlong " . $arg['name'] . ";\n";
            } elseif ($arg['type'] == "bool" || $arg['type'] == "boolean") {
                $types .= "\tzend_bool " . $arg['name'] . ";\n";
            } elseif ($arg['type'] == "double" || $arg['type'] == "float") {
                $types .= "\tdouble " . $arg['name'] . ";\n";
            } elseif ($arg['type'] == "callback") {
                $types .= "\tzend_fcall_info " . $arg['name'] . ";\n";
                $types .= "\tzend_fcall_info_cache " .
                    $arg['name'] . "_cache;\n";
                $arglong .= ', &' . $arg['name'] . '_cache';
            } elseif ($arg['type'] == "class") {
                $types .= "\tzend_class_entry *" . $arg['name'] . ";\n";
            } elseif ($arg['type'] == "string") {
                $types .= "\tchar *" . $arg['name'] . " = NULL;\n";
                $types .= "\tint " . $arg['name'] . "_len;\n";
                $arglong .= ', &' . $arg['name'] . '_len';
            } elseif ($arg['type'] == "unicode") {
                $types .= "\tUChar *" . $arg['name'] . " = NULL;\n";
                $types .= "\tint " . $arg['name'] . "_len;\n";
                $arglong .= ', &' . $arg['name'] . '_len';
            } elseif ($arg['type'] == "text") {
                $types .= "\tzstr " . $arg['name'] . " = NULL;\n";
                $types .= "\tint " . $arg['name'] . "_len;\n";
                $types .= "\tzend_uchar " . $arg['name'] . "_type;\n";
                $arglong .= ', &' . $arg['name'] . '_len';
                $arglong .= ', &' . $arg['name'] . '_type';
            } elseif ($arg['type'] == "array"
                || $arg['type'] == "object"
                || $arg['type'] == "mixed"
                || $arg['type'] == "array|object"
            ) {
                $types .= "\tzval *" . $arg['name'] . " = NULL;\n";
            } elseif ($arg['type'] == "...") {
                $types .= "\tzval ***" . $arg['name'] . " = NULL;\n";
                $types .= "\tint " . $arg['name'] . "_num;\n";
                $arglong .= ', &' . $arg['name'] . '_num';
            } elseif ($arg['type'] == "resource" || $arg['type'] == "handle") {
                $types .= "\tzval *" . $arg['name'] . " = NULL;\n";
                $resources .= "\tif (" . $arg['name'] . ") {\n" .
                    "\t\tZEND_FETCH_RESOURCE(???, ???, " .
                    $arg['name'] . ", " . $arg['name'] .
                    "_id, \"???\", ???_rsrc_id);\n\t}\n";
                $types .= "\tint " . $arg['name'] . "_id = -1;\n";
            }
        }

        if ($proto['class']) {
            $types .= "\t" . $ext . '_' . strtolower($proto['class']) . " *" .
                $proto['class'] .
                "_obj = (" . $ext . '_' . strtolower($proto['class']) .
                "*)zend_object_store_get_object(getThis() TSRMLS_CC);\n";
        }

        $ret = array();

        $required = (string) $required;
        $ret['arginfo'] = $arginfo . $required . ")\n" .
            $argopts . "ZEND_END_ARG_INFO()\n";

        if ($proto['class']) {
            $vmap = array(
                'public' => 'ZEND_ACC_PUBLIC',
                'protected' => 'ZEND_ACC_PROTECTED',
                'private' => 'ZEND_ACC_PRIVATE'
            );
            $visibility = $vmap[$proto['visibility']];
            if ($proto['static']) {
                $visibility .= '|ZEND_ACC_STATIC';
            }
            $ret['definition'] = '/* {{{ proto ' . $proto['proto'] . "*/\n" .
                'PHP_METHOD(' .
                $proto['class'] . ', ' . $proto['function'] . ")\n{\n";
            $ret['declaration'] = "\tPHP_ME(" .
                $proto['class'] . ', ' . $proto['function'] .
                ",\targinfo_" . $proto['class'] . '_' . $proto['function'] .
                ",\t" . $visibility . ")\n";
            $ret['forwarddecl'] = 'PHP_METHOD(' .
                $proto['class'] . ', ' . $proto['function'] . ");\n";

        } else {
            $ret['definition'] = '/* {{{ proto ' . $proto['proto'] . "*/\n" .
                'PHP_FUNCTION(' . $proto['function'] . ")\n{\n";
            $ret['declaration'] = "\tPHP_FE(" .
                $proto['function'] . ",\targinfo_" . $proto['function'] . ")\n";
            $ret['headerdeclare'] = 'PHP_FUNCTION(' . $proto['function'] . ");\n";
        }

        if (!count($proto['args'])) {
            $ret['definition']
                .= "\tif (zend_parse_parameters_none() == FAILURE) {\n\t\treturn;\n\t}\n";
        } else {
            $ret['definition'] .= $types;
            $ret['definition']
                .= "\tif (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, \"" .
                $argshort . '"' . $arglong .
                ") == FAILURE) {\n\t\tRETURN_NULL();\n\t}\n" . $resources;
        }
        if (!$options['nohelp']) {
            if ($proto['class']) {
                $ret['definition'] .= "\tphp_error(E_WARNING, \"" .
                    $proto['class'] . '::' . $proto['function'] .
                    ": not yet implemented\");\n";
            } else {
                $ret['definition'] .= "\tphp_error(E_WARNING, \"" .
                    $proto['function'] .
                    ": not yet implemented\");\n";
            }
        }
        $ret['definition'] .= "}\n/* }}} */\n\n";

        if ($proto['class']) {
            $classinfo[$proto['class']][$proto['function']] = $ret;
        } else {
            $funcinfo[$proto['function']] = $ret;
        }
        return array($funcinfo, $classinfo);
    }

    public function parseProtos($protofile)
    {
        $file = file($protofile);
        $protos = array();
        foreach ($file as $proto) {
            if (!trim($proto)) {
                continue;
            }
            $protos[] = $this->parseProto($proto);
        }
        return $protos;
    }

    public static function parseProto($proto)
    {
        static $map = array(
            'array' => 'a',
            'array|object' => 'A',
            'bool' => 'b',
            'boolean' => 'b',
            'callback' => 'f',
            'class' => 'C',
            'double' => 'd',
            'float' => 'd',
            'handle' => 'r',
            'int' => 'L',
            'long' => 'L',
            'mixed' => 'z',
            'object' => 'o',
            'resource' => 'r',
            'string' => 's',
            'text' => 'T',
            'unicode' => 'u',
            'void' => '',
            '...' => '*', // if param is not optional, + is used
        );
        $ret = array();
        $ret['function'] = substr($proto, 0, $pos = strpos($proto, '('));
        $ret['static'] = $ret['class'] = false;
        $ret['visibility'] = 'public';
        if (strpos($ret['function'], ' ')) {
            $info = explode(' ', $ret['function']);
            $tried = 0;
            while (count($info) > 2 && ++$tried < 4) {
                if (in_array($info[0], array('private', 'public', 'protected'))) {
                    $ret['visibility'] = array_shift($info);
                }
                if ($info[0] == 'static') {
                    $ret['static'] = true;
                    array_shift($info);
                }
            }
            if ($tried > 2) {
                throw new \Pyrus\Developer\Creator\Exception(
                    'Invalid proto ' . $proto
                );
            }
            $ret['returns'] = $info[0];
            $ret['function'] = $info[1];
        }
        if (strpos($ret['function'], '::')) {
            $info = explode('::', $ret['function']);
            $ret['class'] = $info[0];
            $ret['function'] = $info[1];
        }
        $ret['proto'] = $proto;
        // parse arguments
        $len = strlen($proto);
        $args = explode(
            ',',
            substr(str_replace(array(']',')'), '', trim($proto)), $pos + 1)
        );
        $ret['args'] = array();
        foreach ($args as $index => $arg) {
            if (!trim($arg)) {
                continue;
            }
            $arginfo = explode(' ', str_replace('[', '', trim($arg)));
            $arg = explode(' ', trim($arg));
            $ret['args'][$index]['type'] = $arginfo[0];
            if (!isset($map[$ret['args'][$index]['type']])) {
                $ret['args'][$index]['type'] = 'mixed';
            }
            $ret['args'][$index]['name'] = $arginfo[1];
            if ($index == 0) {
                $ret['args'][$index]['optional'] = $arg[0][0] == '[';
            } else {
                $ret['args'][$index]['optional']
                    = (strpos('[', $lastarg[1]) !== false) || isset($lastarg[2]);
            }
            $lastarg = $arg;

            $ret['args'][$index]['code'] = $map[$ret['args'][$index]['type']];
            if ($ret['args'][$index]['code'] == '*'
                && !$ret['args'][$index]['optional']
            ) {
                $ret['args'][$index]['code'] = '+';
            }
        }
        return $ret;
    }
}
