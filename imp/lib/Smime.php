<?php
/**
 * Copyright 2002-2015 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.horde.org/licenses/gpl.
 *
 * @category  Horde
 * @copyright 2002-2015 Horde LLC
 * @license   http://www.horde.org/licenses/gpl GPL
 * @package   IMP
 */

/**
 * Contains code related to handling S/MIME messages within IMP.
 *
 * @author    Mike Cochrane <mike@graftonhall.co.nz>
 * @category  Horde
 * @copyright 2002-2015 Horde LLC
 * @license   http://www.horde.org/licenses/gpl GPL
 * @package   IMP
 */
class IMP_Smime
{
    /* Name of the S/MIME public key field in addressbook. */
    const PUBKEY_FIELD = 'smimePublicKey';

    /* Encryption type constants. */
    const ENCRYPT = 'smime_encrypt';
    const SIGN = 'smime_sign';
    const SIGNENC = 'smime_signenc';

    /**
     * S/MIME object.
     *
     * @var Horde_Crypt_Smime
     */
    protected $_smime;

    /**
     * Return whether PGP support is current enabled in IMP.
     *
     * @return boolean  True if PGP support is enabled.
     */
    public static function enabled()
    {
        global $conf, $prefs;

        return (!empty($conf['openssl']['path']) &&
                $prefs->getValue('use_smime') &&
                Horde_Util::extensionExists('openssl'));
    }

    /**
     * Constructor.
     *
     * @param Horde_Crypt_Smime $pgp  S/MIME object.
     */
    public function __construct(Horde_Crypt_Smime $smime)
    {
        $this->_smime = $smime;
    }

    /**
     * Return the list of available encryption options for composing.
     *
     * @return array  Keys are encryption type constants, values are gettext
     *                strings describing the encryption type.
     */
    public function encryptList()
    {
        $ret = array();

        if ($GLOBALS['registry']->hasMethod('contacts/getField') ||
            $GLOBALS['injector']->getInstance('Horde_Core_Hooks')->hookExists('smime_key', 'imp')) {
            $ret += array(
                self::ENCRYPT => _("S/MIME Encrypt Message")
            );
        }

        if ($this->getPersonalPrivateKey()) {
            $ret += array(
                self::SIGN => _("S/MIME Sign Message"),
                self::SIGNENC => _("S/MIME Sign/Encrypt Message")
            );
        }

        return $ret;
    }

    /**
     * Add the personal public key to the prefs.
     *
     * @param mixed $key  The public key to add (either string or array).
     */
    public function addPersonalPublicKey($key)
    {
        $GLOBALS['prefs']->setValue('smime_public_key', (is_array($key)) ? implode('', $key) : $key);
    }

    /**
     * Add the personal private key to the prefs.
     *
     * @param mixed $key  The private key to add (either string or array).
     */
    public function addPersonalPrivateKey($key)
    {
        $GLOBALS['prefs']->setValue('smime_private_key', (is_array($key)) ? implode('', $key) : $key);
    }

    /**
     * Add the list of additional certs to the prefs.
     *
     * @param mixed $key  The private key to add (either string or array).
     */
    public function addAdditionalCert($key)
    {
        $GLOBALS['prefs']->setValue('smime_additional_cert', (is_array($key)) ? implode('', $key) : $key);
    }

    /**
     * Get the personal public key from the prefs.
     *
     * @return string  The personal S/MIME public key.
     */
    public function getPersonalPublicKey()
    {
        return $GLOBALS['prefs']->getValue('smime_public_key');
    }

    /**
     * Get the personal private key from the prefs.
     *
     * @return string  The personal S/MIME private key.
     */
    public function getPersonalPrivateKey()
    {
        return $GLOBALS['prefs']->getValue('smime_private_key');
    }

    /**
     * Get any additional certificates from the prefs.
     *
     * @return string  Additional signing certs for inclusion.
     */
    public function getAdditionalCert()
    {
        return $GLOBALS['prefs']->getValue('smime_additional_cert');
    }

    /**
     * Deletes the specified personal keys from the prefs.
     */
    public function deletePersonalKeys()
    {
        $GLOBALS['prefs']->setValue('smime_public_key', '');
        $GLOBALS['prefs']->setValue('smime_private_key', '');
        $GLOBALS['prefs']->setValue('smime_additional_cert', '');
        $this->unsetPassphrase();
    }

    /**
     * Add a public key to an address book.
     *
     * @param string $cert  A public certificate to add.
     *
     * @throws Horde_Exception
     */
    public function addPublicKey($cert)
    {
        list($name, $email) = $this->_smime->publicKeyInfo($cert);

        $GLOBALS['registry']->call('contacts/addField', array($email, $name, self::PUBKEY_FIELD, $cert, $GLOBALS['prefs']->getValue('add_source')));
    }

    /**
     * Get information about a public certificate.
     *
     * @param string $cert  The public certificate.
     *
     * @return array  Two element array: the name and e-mail for the cert.
     * @throws Horde_Crypt_Exception
     */
    public function publicKeyInfo($cert)
    {
        /* Make sure the certificate is valid. */
        $key_info = openssl_x509_parse($cert);
        if (!is_array($key_info) || !isset($key_info['subject'])) {
            throw new Horde_Crypt_Exception(_("Not a valid public key."));
        }

        /* Add key to the user's address book. */
        $email = $this->_smime->getEmailFromKey($cert);
        if (is_null($email)) {
            throw new Horde_Crypt_Exception(_("No email information located in the public key."));
        }

        /* Get the name corresponding to this key. */
        if (isset($key_info['subject']['CN'])) {
            $name = $key_info['subject']['CN'];
        } elseif (isset($key_info['subject']['OU'])) {
            $name = $key_info['subject']['OU'];
        } else {
            $name = $email;
        }

        return array($name, $email);
    }

    /**
     * Returns the params needed to encrypt a message being sent to the
     * specified email address(es).
     *
     * @param Horde_Mail_Rfc822_List $addr  The recipient addresses.
     *
     * @return array  The list of parameters needed by encrypt().
     * @throws Horde_Crypt_Exception
     */
    protected function _encryptParameters(Horde_Mail_Rfc822_List $addr)
    {
        return array(
            'pubkey' => array_map(array($this, 'getPublicKey'), $addr->bare_addresses),
            'type' => 'message'
        );
    }

    /**
     * Retrieves a public key by e-mail.
     * The key will be retrieved from a user's address book(s).
     *
     * @param string $address  The e-mail address to search for.
     *
     * @return string  The S/MIME public key requested.
     * @throws Horde_Exception
     */
    public function getPublicKey($address)
    {
        global $injector, $registry;

        try {
            $key = $injector->getInstance('Horde_Core_Hooks')->callHook(
                'smime_key',
                'imp',
                array($address)
            );
            if ($key) {
                return $key;
            }
        } catch (Horde_Exception_HookNotSet $e) {}

        $contacts = $injector->getInstance('IMP_Contacts');

        try {
            $key = $registry->call('contacts/getField', array($address, self::PUBKEY_FIELD, $contacts->sources, true, true));
        } catch (Horde_Exception $e) {
            /* See if the address points to the user's public key. */
            $personal_pubkey = $this->getPersonalPublicKey();
            if (!empty($personal_pubkey) &&
                $injector->getInstance('IMP_Identity')->hasAddress($address)) {
                return $personal_pubkey;
            }

            throw $e;
        }

        /* If more than one public key is returned, just return the first in
         * the array. There is no way of knowing which is the "preferred" key,
         * if the keys are different. */
        return is_array($key) ? reset($key) : $key;
    }

    /**
     * Retrieves all public keys from a user's address book(s).
     *
     * @return array  All S/MIME public keys available.
     * @throws Horde_Crypt_Exception
     */
    public function listPublicKeys()
    {
        $sources = $GLOBALS['injector']->getInstance('IMP_Contacts')->sources;

        return empty($sources)
            ? array()
            : $GLOBALS['registry']->call('contacts/getAllAttributeValues', array(self::PUBKEY_FIELD, $sources));
    }

    /**
     * Deletes a public key from a user's address book(s) by e-mail.
     *
     * @param string $email  The e-mail address to delete.
     *
     * @throws Horde_Crypt_Exception
     */
    public function deletePublicKey($email)
    {
        global $injector, $registry;

        $registry->call(
            'contacts/deleteField',
            array(
                $email,
                self::PUBKEY_FIELD,
                $injector->getInstance('IMP_Contacts')->sources
            )
        );
    }

    /**
     * Returns the parameters needed for signing a message.
     *
     * @return array  The list of parameters needed by encrypt().
     */
    protected function _signParameters()
    {
        return array(
            'type' => 'signature',
            'pubkey' => $this->getPersonalPublicKey(),
            'privkey' => $this->getPersonalPrivateKey(),
            'passphrase' => $this->getPassphrase(),
            'sigtype' => 'detach',
            'certs' => $this->getAdditionalCert()
        );
    }

    /**
     * Verifies a signed message with a given public key.
     *
     * @param string $text  The text to verify.
     *
     * @return stdClass  See Horde_Crypt_Smime::verify().
     * @throws Horde_Crypt_Exception
     */
    public function verifySignature($text)
    {
        global $conf;

        return $this->_smime->verify(
            $text,
            empty($conf['openssl']['cafile']) ? array() : $conf['openssl']['cafile']
        );
    }

    /**
     * Decrypt a message with user's public/private keypair.
     *
     * @param string $text  The text to decrypt.
     *
     * @return string  See Horde_Crypt_Smime::decrypt().
     * @throws Horde_Crypt_Exception
     */
    public function decryptMessage($text)
    {
        return $this->_smime->decrypt($text, array(
            'type' => 'message',
            'pubkey' => $this->getPersonalPublicKey(),
            'privkey' => $this->getPersonalPrivateKey(),
            'passphrase' => $this->getPassphrase()
        ));
    }

    /**
     * Gets the user's passphrase from the session cache.
     *
     * @return mixed  The passphrase, if set.  Returns false if the passphrase
     *                has not been loaded yet.  Returns null if no passphrase
     *                is needed.
     */
    public function getPassphrase()
    {
        global $session;

        $private_key = $GLOBALS['prefs']->getValue('smime_private_key');
        if (empty($private_key)) {
            return false;
        }

        if ($session->exists('imp', 'smime_passphrase')) {
            return $session->get('imp', 'smime_passphrase');
        } elseif (!$session->exists('imp', 'smime_null_passphrase')) {
            $session->set(
                'imp',
                'smime_null_passphrase',
                $this->_smime->verifyPassphrase($private_key, null)
                    ? null
                    : false
            );
        }

        return $session->get('imp', 'smime_null_passphrase');
    }

    /**
     * Store's the user's passphrase in the session cache.
     *
     * @param string $passphrase  The user's passphrase.
     *
     * @return boolean  Returns true if correct passphrase, false if incorrect.
     */
    public function storePassphrase($passphrase)
    {
        global $session;

        if ($this->_smime->verifyPassphrase($this->getPersonalPrivateKey(), $passphrase) !== false) {
            $session->set('imp', 'smime_passphrase', $passphrase, $session::ENCRYPT);
            return true;
        }

        return false;
    }

    /**
     * Clear the passphrase from the session cache.
     */
    public function unsetPassphrase()
    {
        global $session;

        $session->remove('imp', 'smime_null_passphrase');
        $session->remove('imp', 'smime_passphrase');
    }

    /**
     * Encrypt a MIME_Part using S/MIME using IMP defaults.
     *
     * @param Horde_Mime_Part $mime_part     The object to encrypt.
     * @param Horde_Mail_Rfc822_List $recip  The recipient address(es).
     *
     * @return Horde_Mime_Part  See Horde_Crypt_Smime::encryptMIMEPart().
     * @throws Horde_Crypt_Exception
     */
    public function encryptMimePart($mime_part,
                                    Horde_Mail_Rfc822_List $recip)
    {
        return $this->_smime->encryptMIMEPart(
            $mime_part,
            $this->_encryptParameters($recip)
        );
    }

    /**
     * Sign a MIME_Part using S/MIME using IMP defaults.
     *
     * @param MIME_Part $mime_part  The MIME_Part object to sign.
     *
     * @return MIME_Part  See Horde_Crypt_Smime::signMIMEPart().
     * @throws Horde_Crypt_Exception
     */
    public function signMimePart($mime_part)
    {
        return $this->_smime->signMIMEPart(
            $mime_part,
            $this->_signParameters()
        );
    }

    /**
     * Sign and encrypt a MIME_Part using S/MIME using IMP defaults.
     *
     * @param Horde_Mime_Part $mime_part     The object to sign and encrypt.
     * @param Horde_Mail_Rfc822_List $recip  The recipient address(es).
     *
     * @return Horde_Mime_Part  See
     *                          Horde_Crypt_Smime::signAndencryptMIMEPart().
     * @throws Horde_Crypt_Exception
     */
    public function signAndEncryptMimePart($mime_part,
                                           Horde_Mail_Rfc822_List $recip)
    {
        return $this->_smime->signAndEncryptMIMEPart(
            $mime_part,
            $this->_signParameters(),
            $this->_encryptParameters($recip)
        );
    }

    /**
     * Store the public/private/additional certificates in the preferences
     * from a given PKCS 12 file.
     *
     * @param string $pkcs12    The PKCS 12 data.
     * @param string $password  The password of the PKCS 12 file.
     * @param string $pkpass    The password to use to encrypt the private key.
     *
     * @throws Horde_Crypt_Exception
     */
    public function addFromPKCS12($pkcs12, $password, $pkpass = null)
    {
        global $conf;

        $sslpath = empty($conf['openssl']['path'])
            ? null
            : $conf['openssl']['path'];

        $params = array('sslpath' => $sslpath, 'password' => $password);
        if (!empty($pkpass)) {
            $params['newpassword'] = $pkpass;
        }

        $result = $this->_smime->parsePKCS12Data($pkcs12, $params);
        $this->addPersonalPrivateKey($result->private);
        $this->addPersonalPublicKey($result->public);
        $this->addAdditionalCert($result->certs);
    }

    /**
     * Extract the contents from signed S/MIME data.
     *
     * @param string $data  The signed S/MIME data.
     *
     * @return string  The contents embedded in the signed data.
     * @throws Horde_Crypt_Exception
     */
    public function extractSignedContents($data)
    {
        global $conf;

        $sslpath = empty($conf['openssl']['path'])
            ? null
            : $conf['openssl']['path'];

        return $this->_smime->extractSignedContents($data, $sslpath);
    }

    /**
     * Check for the presence of the OpenSSL extension to PHP.
     *
     * @throws Horde_Crypt_Exception
     */
    public function checkForOpenSsl()
    {
        $this->_smime->checkForOpenSSL();
    }

    /**
     * Convert a PEM format certificate to readable HTML version.
     *
     * @param string $cert   PEM format certificate.
     *
     * @return string  HTML detailing the certificate.
     */
    public function certToHTML($cert)
    {
        return $this->_smime->certToHTML($cert);
    }

    /**
     * Extract the contents of a PEM format certificate to an array.
     *
     * @param string $cert  PEM format certificate.
     *
     * @return array  All extractable information about the certificate.
     */
    public function parseCert($cert)
    {
        return $this->_smime->parseCert($cert);
    }

}
