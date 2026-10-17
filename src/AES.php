<?php

//------------------------------------------------------------------------------
// File:         AES.pm
//
// Description:  AES encryption with cipher-block chaining
//
// Revisions:    2010/10/14 - P. Harvey Created
//
// References:   1) http://www.hoozi.com/Articles/AESEncryption.htm
//               2) http://www.csrc.nist.gov/publications/fips/fips197/fips-197.pdf
//               3) http://www.faqs.org/rfcs/rfc3602.html
//
// PHP port of Image::ExifTool::AES 1.01. The file lexicals ($nr, @cbc, @state, @RoundKey,
// @sbox, @rsbox, @rcon) are in tables/vars/AES.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\Vars\AES as V;

final class AES
{
    public static $VERSION = '1.01';

    //------------------------------------------------------------------------------
    // This function produces 4*($nr+1) round keys.
    // The round keys are used in each round to encrypt the states.
    // Inputs: 0) key string (must be 16, 24 or 32 bytes long)
    public static function KeyExpansion($key)
    {
        $k = array_values(unpack('C*', $key));  // convert the key into a byte array
        $nk = intdiv(strlen($key), 4);          // number of 32-bit words in the key
        V::$nr = $nk + 6;                       // number of rounds
        $sbox = V::$sbox;
        $RoundKey = &V::$RoundKey;

        // The first round key is the key itself.
        for ($i = 0; $i < $nk * 4; ++$i) {
            $RoundKey[$i] = $k[$i];
        }
        // All other round keys are found from the previous round keys.
        for ($i = $nk; $i < (4 * (V::$nr + 1)); ++$i) {

            $temp = [$RoundKey[($i-1)*4], $RoundKey[($i-1)*4+1], $RoundKey[($i-1)*4+2], $RoundKey[($i-1)*4+3]];

            if ($i % $nk == 0) {
                // rotate the 4 bytes in a word to the left once
                // [a0,a1,a2,a3] becomes [a1,a2,a3,a0]
                // and take a four-byte input word and apply the S-box
                // to each of the four bytes to produce an output word.
                $temp = [$sbox[$temp[1]], $sbox[$temp[2]], $sbox[$temp[3]], $sbox[$temp[0]]];

                $temp[0] = $temp[0] ^ V::$rcon[intdiv($i, $nk)];

            } elseif ($nk > 6 && $i % $nk == 4) {

                $temp = [$sbox[$temp[0]], $sbox[$temp[1]], $sbox[$temp[2]], $sbox[$temp[3]]];
            }
            $RoundKey[$i*4+0] = $RoundKey[($i-$nk)*4+0] ^ $temp[0];
            $RoundKey[$i*4+1] = $RoundKey[($i-$nk)*4+1] ^ $temp[1];
            $RoundKey[$i*4+2] = $RoundKey[($i-$nk)*4+2] ^ $temp[2];
            $RoundKey[$i*4+3] = $RoundKey[($i-$nk)*4+3] ^ $temp[3];
        }
    }

    //------------------------------------------------------------------------------
    // This function adds the round key to state.
    // The round key is added to the state by an XOR function.
    public static function AddRoundKey($round)
    {
        $state = &V::$state;
        for ($i = 0; $i < 4; ++$i) {
            $k = $round*16 + $i*4;
            for ($j = 0; $j < 4; ++$j) {
                $state[$j][$i] ^= V::$RoundKey[$k + $j];
            }
        }
    }

    //------------------------------------------------------------------------------
    // Substitute the values in the state matrix with values in an S-box
    public static function SubBytes()
    {
        $state = &V::$state;
        for ($i = 0; $i < 4; ++$i) {
            for ($j = 0; $j < 4; ++$j) $state[$i][$j] = V::$sbox[$state[$i][$j]];
        }
    }

    public static function InvSubBytes()
    {
        $state = &V::$state;
        for ($i = 0; $i < 4; ++$i) {
            for ($j = 0; $j < 4; ++$j) $state[$i][$j] = V::$rsbox[$state[$i][$j]];
        }
    }

    //------------------------------------------------------------------------------
    // Shift the rows in the state to the left.
    // Each row is shifted with different offset.
    // Offset = Row number. So the first row is not shifted.
    public static function ShiftRows()
    {
        $s = &V::$state;
        // rotate first row 1 columns to left
        $s[1] = [$s[1][1], $s[1][2], $s[1][3], $s[1][0]];

        // rotate second row 2 columns to left
        $s[2] = [$s[2][2], $s[2][3], $s[2][0], $s[2][1]];

        // rotate third row 3 columns to left
        $s[3] = [$s[3][3], $s[3][0], $s[3][1], $s[3][2]];
    }

    public static function InvShiftRows()
    {
        $s = &V::$state;
        // rotate first row 1 columns to right
        $s[1] = [$s[1][3], $s[1][0], $s[1][1], $s[1][2]];

        // rotate second row 2 columns to right
        $s[2] = [$s[2][2], $s[2][3], $s[2][0], $s[2][1]];

        // rotate third row 3 columns to right
        $s[3] = [$s[3][1], $s[3][2], $s[3][3], $s[3][0]];
    }

    //------------------------------------------------------------------------------
    // Find the product of {02} and the argument to xtime modulo 0x1b
    // Note: returns an integer which may need to be trimmed to 8 bits
    public static function xtime($x)
    {
        return ($x << 1) ^ ((($x >> 7) & 1) * 0x1b);
    }

    //------------------------------------------------------------------------------
    // Multiply numbers in the field GF(2^8)
    public static function Mult($x, $y)
    {
        return (($y & 1) * $x) ^
               (($y >> 1 & 1) * self::xtime($x)) ^
               (($y >> 2 & 1) * self::xtime(self::xtime($x))) ^
               (($y >> 3 & 1) * self::xtime(self::xtime(self::xtime($x)))) ^
               (($y >> 4 & 1) * self::xtime(self::xtime(self::xtime(self::xtime($x)))));
    }

    //------------------------------------------------------------------------------
    // Mix the columns of the state matrix
    public static function MixColumns()
    {
        $state = &V::$state;
        for ($i = 0; $i < 4; ++$i) {
            $t0 = $state[0][$i];
            $t2 = $state[0][$i] ^ $state[1][$i] ^ $state[2][$i] ^ $state[3][$i];
            $t1 = $state[0][$i] ^ $state[1][$i]; $t1 = self::xtime($t1) & 0xff; $state[0][$i] ^= $t1 ^ $t2;
            $t1 = $state[1][$i] ^ $state[2][$i]; $t1 = self::xtime($t1) & 0xff; $state[1][$i] ^= $t1 ^ $t2;
            $t1 = $state[2][$i] ^ $state[3][$i]; $t1 = self::xtime($t1) & 0xff; $state[2][$i] ^= $t1 ^ $t2;
            $t1 = $state[3][$i] ^ $t0;           $t1 = self::xtime($t1) & 0xff; $state[3][$i] ^= $t1 ^ $t2;
        }
    }

    public static function InvMixColumns()
    {
        $state = &V::$state;
        for ($i = 0; $i < 4; ++$i) {
            $a = $state[0][$i];
            $b = $state[1][$i];
            $c = $state[2][$i];
            $d = $state[3][$i];
            $state[0][$i] = (self::Mult($a, 0x0e) ^ self::Mult($b, 0x0b) ^ self::Mult($c, 0x0d) ^ self::Mult($d, 0x09)) & 0xff;
            $state[1][$i] = (self::Mult($a, 0x09) ^ self::Mult($b, 0x0e) ^ self::Mult($c, 0x0b) ^ self::Mult($d, 0x0d)) & 0xff;
            $state[2][$i] = (self::Mult($a, 0x0d) ^ self::Mult($b, 0x09) ^ self::Mult($c, 0x0e) ^ self::Mult($d, 0x0b)) & 0xff;
            $state[3][$i] = (self::Mult($a, 0x0b) ^ self::Mult($b, 0x0d) ^ self::Mult($c, 0x09) ^ self::Mult($d, 0x0e)) & 0xff;
        }
    }

    //------------------------------------------------------------------------------
    // Encrypt (Cipher) or decrypt (InvCipher) a block of data with CBC
    // Inputs: 0) string to cipher (must be 16 bytes long)
    // Returns: cipher'd string
    public static function Cipher($in)
    {
        $in = array_values(unpack('C*', $in));  // unpack input plaintext
        $state = &V::$state;
        $cbc = &V::$cbc;
        $nr = V::$nr;

        // copy the input PlainText to state array and apply the CBC
        for ($i = 0; $i < 4; ++$i) {
            for ($j = 0; $j < 4; ++$j) {
                $k = $i*4 + $j;
                $state[$j][$i] = $in[$k] ^ $cbc[$k];
            }
        }

        // add the First round key to the state before starting the rounds
        self::AddRoundKey(0);

        // there will be $nr rounds; the first $nr-1 rounds are identical
        for ($round = 1; ; ++$round) {
            self::SubBytes();
            self::ShiftRows();
            if ($round < $nr) {
                self::MixColumns();
                self::AddRoundKey($round);
            } else {
                // MixColumns() is not used in the last round
                self::AddRoundKey($nr);
                break;
            }
        }

        // the encryption process is over
        // copy the state array to output array (and save for CBC)
        for ($i = 0; $i < 4; ++$i) {
            for ($j = 0; $j < 4; ++$j) {
                $cbc[$i*4+$j] = $state[$j][$i];
            }
        }
        return pack('C*', ...$cbc); // return packed ciphertext
    }

    public static function InvCipher($in)
    {
        $in = array_values(unpack('C*', $in));  // unpack input ciphertext
        $state = &V::$state;
        $out = [];

        // copy the input CipherText to state array
        for ($i = 0; $i < 4; ++$i) {
            for ($j = 0; $j < 4; ++$j) {
                $state[$j][$i] = $in[$i*4 + $j];
            }
        }

        // add the First round key to the state before starting the rounds
        self::AddRoundKey(V::$nr);

        // there will be $nr rounds; the first $nr-1 rounds are identical
        for ($round = V::$nr - 1; ; --$round) {
            self::InvShiftRows();
            self::InvSubBytes();
            self::AddRoundKey($round);
            // InvMixColumns() is not used in the last round
            if ($round <= 0) break;
            self::InvMixColumns();
        }

        // copy the state array to output array and reverse the CBC
        for ($i = 0; $i < 4; ++$i) {
            for ($j = 0; $j < 4; ++$j) {
                $k = $i*4 + $j;
                $out[$k] = $state[$j][$i] ^ V::$cbc[$k];
            }
        }
        V::$cbc = $in;              // update CBC for next block
        return pack('C*', ...$out); // return packed plaintext
    }

    //------------------------------------------------------------------------------
    // Encrypt/Decrypt using AES-CBC algorithm (with fixed 16-byte blocks)
    // Inputs: 0) data reference (with leading 16-byte initialization vector when decrypting)
    //         1) encryption key (16, 24 or 32 bytes for AES-128, AES-192 or AES-256)
    //         2) encrypt flag (false for decryption, true with length 16 bytes to
    //            encrypt using this as the CBC IV, or true with other length to
    //            encrypt with a randomly-generated IV)
    //         3) flag to disable padding
    // Returns: error string, or undef on success
    // Notes: encrypts/decrypts data in place (encrypted data returned with leading IV)
    public static function Crypt(&$dataPt, $key, $encrypt = null, $noPad = null)
    {
        // validate key length
        $keyLen = strlen($key);
        if (!($keyLen == 16 or $keyLen == 24 or $keyLen == 32)) {
            return "Invalid AES key length ($keyLen)";
        }
        $dataPt = (string) $dataPt;
        $partLen = strlen($dataPt) % 16;
        if ($encrypt) {
            if (strlen($encrypt) == 16) {
                V::$cbc = array_values(unpack('C*', $encrypt));
            } else {
                // generate a random 16-byte CBC initialization vector
                // (Perl seeds rand() once: random_int needs no seeding)
                for ($i = 0; $i < 16; ++$i) {
                    V::$cbc[$i] = random_int(0, 255);
                }
                $encrypt = pack('C*', ...V::$cbc);
            }
            $dataPt = $encrypt . $dataPt;   // add IV to the start of the data
            // add required padding so we can recover the
            // original string length after decryption
            // (padding bytes have value set to padding length)
            $padLen = 16 - $partLen;
            if (!($padLen == 16 and $noPad)) $dataPt .= str_repeat(chr($padLen), $padLen);
            $pos = 16;      // start encrypting at byte 16 (after the IV)
        } elseif ($partLen) {
            return 'Invalid AES ciphertext length';
        } elseif (strlen($dataPt) >= 32) {
            // take the CBC initialization vector from the start of the data
            V::$cbc = array_values(unpack('C16', $dataPt));
            $dataPt = substr($dataPt, 16);
            $pos = 0;       // start decrypting from byte 0 (now that IV is removed)
        } else {
            $dataPt = '';   // empty text
            return null;
        }
        // the KeyExpansion routine must be called before encryption
        self::KeyExpansion($key);

        // loop through the data and convert in blocks
        // (PHP: build the result instead of replacing each block in place)
        $dataLen = strlen($dataPt);
        $last = $dataLen - 16;
        $out = substr($dataPt, 0, $pos);
        while ($pos <= $last) {
            // cipher this block
            $block = substr($dataPt, $pos, 16);
            $out .= $encrypt ? self::Cipher($block) : self::InvCipher($block);
            $pos += 16;
        }
        $dataPt = $out . substr($dataPt, $pos);
        if (!($encrypt or $noPad)) {
            // remove padding if necessary (padding byte value gives length of padding)
            $padLen = ord(substr($dataPt, -1, 1));
            if ($padLen > 16) return 'AES decryption error (invalid pad byte)';
            $dataPt = substr($dataPt, 0, $dataLen - $padLen);
        }
        return null;
    }
}
