<?php

/**
 * ~~summary~~
 *
 * ~~description~~
 *
 * PHP version 5.3
 *
 * @category Pyrus
 * @package  Pyrus_Developer
 * @author   Greg Beaver <greg@chiaraquartet.net>
 * @license  http://www.opensource.org/licenses/bsd-license.php New BSD License
 * @version  GIT: $Id$
 * @link     https://github.com/pyrus/Pyrus_Developer
 */

namespace Pyrus\Developer\Creator;

use Pyrus\Channel\RemotePackage;
use Pyrus\Package;
use Pyrus\Package\CreatorInterface;

class Phar implements CreatorInterface
{
    /**
     * @var Phar
     */
    protected $phar;
    protected $others;
    protected $path;
    protected $stub;
    protected $format;
    protected $compression;
    protected $pkcs12;
    protected $passphrase;
    protected $x509cert;
    protected $publickey;
    private $_classname = 'Phar';
    private $_started = false;

    /**
     * Archive creator for phar, tar, tgz and zip archives.
     *
     * @param string       $path        path to primary archive
     * @param string|false $stub        stub or false
     *     to use default stub of phar archives
     * @param int          $fileformat  one of
     *     Phar::TAR, Phar::PHAR, or Phar::ZIP
     * @param int          $compression if the archive can be compressed
     *     (phar and tar), one of Phar::GZ, Phar::BZ2
     *     or Phar::NONE for no compression
     * @param array        $others      an array of arrays containing
     *     information on additional archives to create.  The indices are:
     *
     *               0. extension (tar/tgz/zip)
     *               1. format (Phar::TAR, Phar::ZIP, Phar::PHAR)
     *               2. compression (Phar::GZ, Phar::BZ2, Phar::NONE)
     * @param string       $releaser    
     * @param Package      $new         
     * @param string       $pkcs12      PKCS12 certificate to be used to sign
     *     the archive.
     *     This must be a certificate issued by a certificate authority,
     *     self-signed certs will not be accepted by Pyrus
     * @param string       $passphrase  passphrase, if any,
     *     for the PKCS12 certificate.
     */
    public function __construct(
        $path,
        $stub = false,
        $fileformat = \Phar::TAR,
        $compression = \Phar::GZ,
        array $others = null,
        $releaser = null,
        Package $new = null,
        $pkcs12 = null,
        $passphrase = ''
    ) {
        if (!class_exists('Phar')) {
            throw new Exception(
                'Phar extension is not available'
            );
        }
        if (!\Phar::canWrite() || !\Phar::isValidPharFilename($path, true)) {
            $this->_classname = 'PharData';
        }
        $this->path = $path;
        $this->compression = $compression;
        $this->format = $fileformat;
        $this->others = $others;
        $this->stub = $stub;
        if ($pkcs12 && !extension_loaded('openssl')) {
            throw new Exception(
                'Unable to use ' .
                'OpenSSL signing of phars, enable the openssl PHP extension'
            );
        }
        $this->pkcs12 = $pkcs12;
        $this->passphrase = $passphrase;
        if (null !== $this->pkcs12) {
            $cert = array();
            $pkcs = openssl_pkcs12_read(
                file_get_contents($this->pkcs12),
                $cert,
                $this->passphrase
            );
            if (!$pkcs) {
                throw new Exception('Unable to process openssl key');
            }
            $private = openssl_pkey_get_private($cert['pkey']);
            if (!$private) {
                throw new Exception('Unable to extract private openssl key');
            }
            $pub = openssl_pkey_get_public($cert['cert']);
            $info = openssl_x509_parse($cert['cert']);
            $details = openssl_pkey_get_details($pub);
            if (true !== openssl_x509_checkpurpose(
                $cert['cert'],
                X509_PURPOSE_SSL_SERVER,
                RemotePackage::authorities()
            )
            ) {
                throw new Exception(
                    'releasing maintainer\'s certificate is invalid'
                );
            }
            // now verify that this cert is in fact the releasing maintainer's
            // certificate by verifying that alternate name is the releaser's
            // email address
            if (!isset($info['subject'])
                || !isset($info['subject']['emailAddress'])
            ) {
                throw new Exception(
                    'releasing maintainer\'s certificate does not contain' .
                    ' an alternate name corresponding to' .
                    ' the releaser\'s email address'
                );
            }

            if ($info['subject']['emailAddress'] != $new->maintainer[$releaser]->email) {
                throw new Exception(
                    'releasing maintainer\'s certificate ' .
                    'alternate name does not match ' .
                    'the releaser\'s email address ' .
                    $new->maintainer[$releaser]->email
                );
            }

            $pkey = '';
            openssl_pkey_export($private, $pkey);
            $this->x509cert = $cert['cert'];
            $this->publickey = $details['key'];
            $this->privatekey = $pkey;
        }
    }

    /**
     * save a file inside this package
     * 
     * @param string          $path         relative path within the package
     * @param string|resource $fileOrStream file contents or open file handle
     * 
     * @return void
     */
    public function addFile($path, $fileOrStream)
    {
        if (!$this->_started) {
            // save package.xml name
            $this->phar->setMetadata($path);
            $this->_started = true;
        }
        $this->phar[$path] = $fileOrStream;
    }

    public function addDir($path)
    {
        $this->phar->buildFromDirectory($path);
    }

    /**
     * Initialize the package creator
     * 
     * @return void
     */
    public function init()
    {
        try {
            if (file_exists($this->path)) {
                @unlink($this->path);
            }
            $ext = (string) strstr(basename($this->path), '.');
            $a = $this->_classname;
            $this->phar = new $a($this->path);
            if ($this->phar instanceof \Phar) {
                $this->phar = $this->phar->convertToExecutable(
                    $this->format,
                    $this->compression,
                    $ext
                );
            } else {
                $this->phar = $this->phar->convertToData(
                    $this->format,
                    $this->compression,
                    $ext
                );
            }
            $this->phar->startBuffering();
            if ($this->phar instanceof \Phar && $this->stub) {
                $this->phar->setStub($this->stub);
            }
        } catch (Exception $e) {
            throw new Exception(
                'Cannot open Phar archive ' . $this->path,
                $e
            );
        }
        $this->_started = false;
    }

    /**
     * Create an internal directory, creating parent directories as needed
     *
     * @param string $dir
     * 
     * @return void
     */
    public function mkdir($dir)
    {
        $this->phar->addEmptyDir($dir);
    }

    /**
     * Finish saving the package
     * 
     * @return void
     */
    public function close()
    {
        if ($this->phar->isFileFormat(\Phar::ZIP)
            && $this->compression !== \Phar::NONE
        ) {
            $this->phar->compressFiles($this->compression);
        }
        if (null !== $this->pkcs12) {
            $certpath = str_replace(
                array('.tar', '.zip', '.tgz', '.phar'),
                array('', '', '', ''),
                $this->path
            );
            $this->phar->setSignatureAlgorithm(
                \Phar::OPENSSL,
                $this->privatekey
            );
            file_put_contents($certpath . '.pem', $this->x509cert);
            file_put_contents($this->path . '.pubkey', $this->publickey);
        } elseif (!$this->phar->isFileFormat(\Phar::ZIP)) {
            $this->phar->setSignatureAlgorithm(\Phar::SHA1);
        }

        $this->phar->stopBuffering();
        $ext = (string) strstr(basename($this->path), '.');
        $newphar = $this->phar;
        if (count($this->others)) {
            foreach ($this->others as $pathinfo) {
                // remove the old file
                $pubkeypath = $newpath = str_replace(
                    array('.tar', '.zip', '.tgz', '.phar'),
                    array('', '', '', ''),
                    $this->path
                );
                $newpath .= '.' .$pathinfo[0];
                if (file_exists($newpath)) {
                    unlink($newpath);
                }
                $extension = $ext . $pathinfo[0];
                $fileformat = $pathinfo[1];
                $compression = $pathinfo[2];

                if ($fileformat != \Phar::PHAR) {
                    $newphar = $newphar->convertToData(
                        $fileformat,
                        $compression,
                        $extension
                    );
                } else {
                    $newphar = $newphar->convertToExecutable(
                        $fileformat,
                        $compression,
                        $extension
                    );
                }
                if (isset($pkey)) {
                    $newphar->setSignatureAlgorithm(
                        \Phar::OPENSSL,
                        $this->privatekey
                    );
                    file_put_contents(
                        $pubkeypath . '.' . $pathinfo[0] . '.pubkey',
                        $this->publickey
                    );
                } else {
                    $newphar->setSignatureAlgorithm(\Phar::SHA1);
                }
            }
        }
    }
}
