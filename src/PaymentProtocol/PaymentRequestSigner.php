<?php

namespace BitWasp\Bitcoin\PaymentProtocol;

use BitWasp\Bitcoin\PaymentProtocol\Protobufs\PaymentRequest as PaymentRequestBuf;
use BitWasp\Bitcoin\PaymentProtocol\Protobufs\X509Certificates as X509CertificatesBuf;

class PaymentRequestSigner
{
    /**
     * @var string
     */
    private $type;

    /**
     * @var int
     */
    private $algoConst;

    /**
     * @var X509CertificatesBuf
     */
    private $certificates;

    /**
     * @var resource
     */
    private $privateKey;

    /**
     * @param string $type
     * @param string $keyFile
     * @param string $certFile
     * @throws \Exception
     */
    public function __construct($type = 'none', $keyFile = '', $certFile = '')
    {
        if (false === in_array($type, ['none','x509+sha1', 'x509+sha256'])) {
            throw new \InvalidArgumentException('Invalid BIP70 signature type');
        }

        $this->type = $type;
        $this->certificates = new X509CertificatesBuf();

        if ($type !== 'none') {
            $this->initialize($keyFile, $certFile);
        }
    }

    /**
     * @return bool
     */
    public function supportsSha256()
    {
        return defined('OPENSSL_ALGO_SHA256');
    }

    /**
     * @param string $keyFile - path to key file
     * @param string $certFile - path to certificate chain file
     * @throws \Exception
     */
    private function initialize($keyFile, $certFile)
    {
        if (false === file_exists($keyFile)) {
            throw new \InvalidArgumentException('Private key file does not exist');
        }

        if (false === file_exists($certFile)) {
            throw new \InvalidArgumentException('Certificate file does not exist');
        }

        if ('x509+sha256' == $this->type && !$this->supportsSha256()) {
            throw new \Exception('Server does not support x.509+SHA256');
        }

        $chain = $this->fetchChain($certFile);
        if (!is_array($chain) || count($chain) == 0) {
            throw new \RuntimeException('Certificate file contains no certificates');
        }

        foreach ($chain as $cert) {
            $this->certificates->addCertificate($cert);
        }

        $pkeyid = openssl_get_privatekey(file_get_contents($keyFile));
        if (false === $pkeyid) {
            throw new \InvalidArgumentException('Private key is invalid');
        }

        $this->privateKey = $pkeyid;
        $this->algoConst = $this->type == 'x509+sha256'
            ? OPENSSL_ALGO_SHA256
            : OPENSSL_ALGO_SHA1;
    }

    /**
     * Applies the configured signature algorithm, adding values to
     * the protobuf: 'pkiType', 'signature', 'pkiData'
     *
     * @param PaymentRequestBuf $request
     * @return PaymentRequestBuf
     * @throws \Exception
     */
    public function apply(PaymentRequestBuf $request)
    {
        $request->setPkiType($this->type);
        $request->setSignature('');

        if ($this->type !== 'none') {
            $signature = '';
            $request->setPkiData($this->certificates->serialize());
            $result = openssl_sign($request->serialize(), $signature, $this->privateKey, $this->algoConst);
            if ($signature === false || $result === false) {
                throw new \Exception('PaymentRequestSigner: Unable to create signature');
            }

            $request->setSignature($signature);
        }

        return $request;
    }

    /**
     * Checks whether the decoded certificate is a root / self-signed certificate
     * @param array $certificate
     * @return bool
     */
    private function isRoot($certificate)
    {
        return $certificate['issuer'] == $certificate['subject'];
    }

    /**
     * Fetches parent certificates using network requests
     * Todo: review use of file_get_contents
     * @param $leafCertificate
     * @return bool|string
     */
    private function fetchCertificateParent($leafCertificate)
    {
        $pattern = '/CA Issuers - URI:(\\S*)/';
        $matches = array();

        $nMatches = preg_match_all($pattern, $leafCertificate['extensions']['authorityInfoAccess'], $matches);
        if ($nMatches == 0) {
            return false;
        }
        foreach ($matches[1] as $url) {
            $parentCert = file_get_contents($url);
            if ($parentCert && $this->parseCertificate($parentCert)) {
                return $parentCert;
            }
        }
        return false;
    }

    /**
     * Parses a PEM or DER certificate
     * @param $certData
     * @return array
     */
    private function parseCertificate($certData)
    {
        $begin = "-----BEGIN CERTIFICATE-----";
        $end = "-----END CERTIFICATE-----";

        if (strpos($certData, $begin) !== false) {
            return openssl_x509_parse($certData);
        }
        $d = $begin . "\n";
        $d .= chunk_split(base64_encode($certData));
        $d .= $end . "\n";
        return openssl_x509_parse($d);
    }

    /**
     * Decode PEM data, return the internal DER data
     * @param string $pem_data - pem certificate data
     * @return string
     */
    private function pem2der($pem_data)
    {
        $begin = "CERTIFICATE-----";
        $end = "-----END";
        if (strpos($pem_data, $begin) === false) {
            return $pem_data;
        }
        $pem_data = substr($pem_data, strpos($pem_data, $begin) + strlen($begin));
        $pem_data = substr($pem_data, 0, strpos($pem_data, $end));
        $der = base64_decode($pem_data);
        return $der;
    }

    /**
     * @param string $leaf - path to a file with certificates
     * @return array|bool
     */
    private function fetchChain($leaf)
    {
        $result = array();
        $leaf = file_get_contents($leaf);
        $cert = $this->parseCertificate($leaf);
        if ($cert === false) {
            return false;
        }

        $certData = self::pem2der($leaf);
        $result[] = $certData;
        while ($cert) {
            $result[] = $certData;
            // Only break after adding Cert Data - allows for self-signed certificates
            if ($this->isRoot($cert)) {
                break;
            }
            $certData = $this->fetchCertificateParent($cert);
            $cert = $this->parseCertificate($certData);
        }

        return $result;
    }
}
