<?php

namespace BitWasp\Bitcoin\Address;

use BitWasp\Bitcoin\Base58;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Crypto\EcAdapter\Key\KeyInterface;
use BitWasp\Bitcoin\Exceptions\UnrecognizedAddressException;
use BitWasp\Bitcoin\Exceptions\UnrecognizedScriptForAddressException;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Script\Classifier\OutputClassifier;
use BitWasp\Bitcoin\Script\P2shScript;
use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Bitcoin\Script\ScriptType;
use BitWasp\Bitcoin\Script\WitnessProgram;
use BitWasp\Bitcoin\Script\WitnessScript;
use BitWasp\Bitcoin\SegwitBech32;
use BitWasp\Buffertools\BufferInterface;

class AddressCreator extends BaseAddressCreator
{
    /**
     * @param string $strAddress
     * @param NetworkInterface $network
     * @return Base58Address|null
     */
    protected function readBase58Address($strAddress, NetworkInterface $network)
    {
        try {
            $data = Base58::decodeCheck($strAddress);
            $prefixByte = $data->slice(0, 1)->getHex();

            if ($prefixByte === $network->getP2shByte()) {
                return new ScriptHashAddress($data->slice(1));
            } else if ($prefixByte === $network->getAddressByte()) {
                return new PayToPubKeyHashAddress($data->slice(1));
            }
        } catch (\Exception $e) {
            // Just return null
        }

        return null;
    }

    /**
     * @param string $strAddress
     * @param NetworkInterface $network
     * @return SegwitAddress|null
     */
    protected function readSegwitAddress($strAddress, NetworkInterface $network)
    {
        try {
            return new SegwitAddress(SegwitBech32::decode($strAddress, $network));
        } catch (\Exception $e) {
            // Just return null
        }

        return null;
    }

    /**
     * @param ScriptInterface $outputScript
     * @return Address|PayToPubKeyHashAddress|ScriptHashAddress|SegwitAddress
     * @throws UnrecognizedScriptForAddressException
     */
    public function fromOutputScript(ScriptInterface $outputScript)
    {
        if ($outputScript instanceof P2shScript || $outputScript instanceof WitnessScript) {
            throw new \RuntimeException("P2shScript & WitnessScript's are not accepted by fromOutputScript");
        }

        $wp = null;
        if ($outputScript->isWitness($wp)) {
            /** @var WitnessProgram $wp */
            return new SegwitAddress($wp);
        }

        $decode = (new OutputClassifier())->decode($outputScript);
        switch ($decode->getType()) {
            case ScriptType::P2PKH:
                /** @var BufferInterface $solution */
                return new PayToPubKeyHashAddress($decode->getSolution());
            case ScriptType::P2SH:
                /** @var BufferInterface $solution */
                return new ScriptHashAddress($decode->getSolution());
            default:
                throw new UnrecognizedScriptForAddressException('Script type is not associated with an address');
        }
    }

    /**
     * @param string $strAddress
     * @param NetworkInterface|null $network
     * @return Address
     * @throws UnrecognizedAddressException
     */
    public function fromString($strAddress, NetworkInterface $network = null)
    {
        $network = $network ?: Bitcoin::getNetwork();

        if (($base58Address = $this->readBase58Address($strAddress, $network))) {
            return $base58Address;
        }

        if (($bech32Address = $this->readSegwitAddress($strAddress, $network))) {
            return $bech32Address;
        }

        throw new UnrecognizedAddressException("Address not understood");
    }

    /**
     * Returns a pay-to-pubkey-hash address for the given public key
     *
     * @param KeyInterface $key
     * @return PayToPubKeyHashAddress
     */
    public function fromKey(KeyInterface $key)
    {
        return new PayToPubKeyHashAddress($key->getPubKeyHash());
    }

    /**
     * Takes the $p2shScript and generates the scriptHash address.
     *
     * @param ScriptInterface $p2shScript
     * @return ScriptHashAddress
     */
    public function fromRedeemScript(ScriptInterface $p2shScript)
    {
        if ($p2shScript instanceof WitnessScript) {
            throw new \LogicException("Cannot create a P2SH address directly for a WitnessScript");
        } else {
            return new ScriptHashAddress($p2shScript->getScriptHash());
        }
    }

    /**
     * @param WitnessProgram $wp
     * @return SegwitAddress
     */
    public function fromWitnessProgram(WitnessProgram $wp)
    {
        return new SegwitAddress($wp);
    }
}
