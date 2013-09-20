<?php  /* hypher.php -- hyphenation using Liang-Knuth algorithm.
	* version 0.1.2 (01.09.2010)
	* Copyright (C) 2008-2010 Sergey Kurakin (sergeykurakin@gmail.com)
	*
	* This program is free software; you can redistribute it and/or modify
	* it under the terms of the GNU Lesser General Public License as
	* published by the Free Software Foundation; either version 3
	* of the License, or (at your option) any later version.
	*
	* This program is distributed in the hope that it will be useful,
	* but WITHOUT ANY WARRANTY; without even the implied warranty of
	* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	* GNU Lesser General Public License for more details.
	*/

require_once 'sk_lib_i.php';

class phpHypher {

	const VERSION = '0.1.2';

	const AUTO_RECOMPILE = 0;
	const NEVER_RECOMPILE = 1;
	const ALWAYS_RECOMPILE = 2;

	protected $internal_encoding;	// internal encoding
	protected $alphabet;		// language alphabet lowercase
	protected $alphabet_uc;		// language alphabet uppercase
	protected $translation;		// translation table for the language
	protected $dictionary;		// compiled dictionary
	protected $min_left_limit;	// left hyphenation limit for the language
	protected $min_right_limit;	// right hyphenation limit for the language

	protected $soft_hyphen = '&shy;';
	protected $io_encoding = '';

	public $proceed_uppercase = false;

	public $left_limit;		// current left hyphenation limit
	public $right_limit;		// current right hyphenation limit
	public $length_limit;		// minimum word length allowing hyphenation
	public $right_limit_last;	// current right hyphenation limit
					// for the last word in paragraph
	public $left_limit_uc;		// current left hyphenation limit
					// for words with first letter in upper case


	// $conffile:	filename of config file
	// $recompile:	necessity to recompile ruleset

	function __construct($conffile, $recompile = self::AUTO_RECOMPILE) {

		if (!is_file($conffile))
			return false;

		$conf = sk_parse_config($conffile);
		if (!$conf)
			return false;

		$path = dirname($conffile);

		if (isset($conf['compiled'][0]))
			$conf['compiled'][0] = $path. '/'. $conf['compiled'][0];

		if (!is_file($conf['compiled'][0]))
			$recompile = self::ALWAYS_RECOMPILE;

		if (isset($conf['rules'])) {
			foreach ($conf['rules'] as $key => $val)
				$conf['rules'][$key] = $path. '/'. $val;
		} else
			return false;

		// define the necessety to remake dictionary
		if ($recompile == self::AUTO_RECOMPILE) {
			$date_out = sk_array_value(stat($conf['compiled'][0]), 'mtime');
			$date_in = sk_array_value(stat($conffile), 'mtime');
			foreach ($conf['rules'] as $val)
				$date_in = max($date_in, sk_array_value(stat($val), 'mtime'));
			if ($date_in > $date_out)
				$recompile = self::ALWAYS_RECOMPILE;
		}

		// recompile the dictionary in case of version mismatch
		if ($recompile != self::ALWAYS_RECOMPILE) {
			$ret = unserialize(file_get_contents($conf['compiled'][0]));
			if (!isset($ret['ver']) || $ret['ver'] !== self::VERSION)
				$recompile = self::ALWAYS_RECOMPILE;
		}

		// recompile and save the dictionary
		if ($recompile == self::ALWAYS_RECOMPILE) {

			$ret = array();

			// parse alphabet
			$ret['alph'] = preg_replace('/\((.+)\>(.+)\)/U', '$1', $conf['alphabet'][0]);
			$ret['alphUC'] = $conf['alphabetUC'][0];

			// make translation table
			if (preg_match_all('/\((.+)\>(.+)\)/U', $conf['alphabet'][0], $matches, PREG_PATTERN_ORDER))
				foreach ($matches[1] as $key => $val)
					$ret['trans'][$val] = $matches[2][$key];
			else $ret['trans'] = array();

			$ret['ll'] = $conf['left_limit'][0];
			$ret['rl'] = $conf['right_limit'][0];
			$ret['enc'] = $conf['internal_encoding'][0];
			$ret['ver'] = self::VERSION;

			foreach ($conf['rules'] as $fnm) if (is_file($fnm)) {
				$in_file = explode("\n", sk_clean_config(file_get_contents($fnm)));

				// first string of the rules file is the encoding of this file
				$encoding = $in_file[0];
				unset($in_file[0]);

				// create rules array: keys -- letters combinations; values -- digital masks
				foreach ($in_file as $str) {

					// translate rules to internal encoding
					if (strcasecmp($encoding, $ret['enc']) != 0)
						$str = @iconv($encoding, $ret['enc'], $str);

					// patterns not containing digits and dots are treated as dictionary words
					// converting ones to pattern
					if (!preg_match('/[\d\.]/', $str)) {
						$str = str_replace('-', '9', $str);
						$str = preg_replace('/(?<=\D)(?=\D)/', '8', $str);
						$str = '.'. $str. '.';
					}	

					// insert zero between the letters
					$str = preg_replace('/(?<=\D)(?=\D)/', '0', $str);
	
					// insert zero on beginning and on the end
					if (preg_match('/^\D/', $str)) $str = '0'. $str;
					if (preg_match('/\D$/', $str)) $str .= '0';

					// make array
					$ind = preg_replace('/[\d\n\s]/', '', $str);
					$vl = preg_replace('/\D/', '', $str);
					if ($ind != '' && $vl != '') {

						$ret['dict'][$ind] = $vl;

						// optimize: if there is, for example, "abcde" pattern
						// then we need "abcd", "abc", "ab" and "a" patterns
						// to be presented
						$sb = $ind;
						do {
							$sb = substr($sb, 0, strlen($sb) - 1);
							if (!isset($ret['dict'][$sb]))
								$ret['dict'][$sb] = 0;
							else
								break;
						} while (strlen($sb) > 1);
					}
				}
			}

			if (isset($conf['compiled'][0]))	
				file_put_contents($conf['compiled'][0], serialize($ret));
		}

		$this->internal_encoding = $ret['enc'];
		$this->alphabet = $ret['alph'];
		$this->alphabet_uc = $ret['alphUC'];
		$this->translation = $ret['trans'];
		$this->dictionary = $ret['dict'];
		$this->min_left_limit = $ret['ll'];
		$this->min_right_limit = $ret['rl'];
		
		$this->check_limits();
	}


	// hyphenate the word, you don't need to call it directly.
	protected function hyphenate_word($instr,  $last_word = false) {

		// convert the word to the internal encoding
		$word = ($to_transcode = ($this->io_encoding &&
			strcasecmp($this->internal_encoding, $this->io_encoding) != 0))
				? @iconv($this->io_encoding, $this->internal_encoding, $instr)
				: $instr;

		// \x5C character (backslash) indicates to not process this world at all
		if (false !== strpos($word, "\x5C"))
			return $instr;

		// convert the first letter to low case
		$word_lower = $word;
		$st_pos = strpos($this->alphabet_uc, $word{0});
		if ($st_pos !== false) {
			$ll = $this->left_limit_uc;
			$word_lower{0} = $this->alphabet{$st_pos};
		} else
			$ll = $this->left_limit;

		$rl = ($last_word) ? $this->right_limit_last : $this->right_limit;

		// check all letters but the first for upper case
		for ($i = 1, $len = strlen($word_lower); $i < $len; $i++) {
			$st_pos = strpos($this->alphabet_uc, $word{$i});
			if ($st_pos !== false) {
				if ($this->proceed_uppercase)
					$word_lower{$i} = $this->alphabet{$st_pos};
				else
					return $instr;
			}
		}

		$word_lower = '.'. $word_lower. '.';
		$word = '.'. $word. '.';
		$len = strlen($word);

		// translate letters
		foreach ($this->translation as $key => $val)
			$word_lower = str_replace($key, $val, $word_lower);
	
		$word_splitted = str_split($word_lower);
		$word_mask = str_split(str_repeat('0', $len + 1));

		// step by step cycle
		for ($i = 0; $i < $len - 1; $i++) {

			// Increasing fragment's length cycle.
			// The first symbol of the word always is dot,
			// so we don't need to check 1-length fragment at the first step
			for ($k = ($i == 0) ? 2 : 1; $k <= $len - $i; $k++) {
				$ind = substr($word_lower, $i, $k);

				// fallback
				if (!isset($this->dictionary[$ind])) break;

				$val = $this->dictionary[$ind];
				if ($val !== 0)
					for ($j = 0; $j <= $k ; $j++)
						$word_mask[$i + $j] = max($word_mask[$i + $j], $val[$j]);
			}
		}

		$ret = '';
		foreach (str_split($word) as $key => $val) if ( $val != '.') {
			$ret .= $val;
			if ($key > $ll - 1 && $key < $len - $rl - 1 && $word_mask[$key + 1] % 2)
				$ret .= $this->soft_hyphen;
		}

		// convert the word back to native encoding
		if ($to_transcode)
			$ret = @iconv($this->internal_encoding, $this->io_encoding, $ret);

		return $ret;
	}


	protected function check_limits() {
		$this->left_limit = max($this->left_limit, $this->min_left_limit);
		$this->right_limit = max($this->right_limit, $this->min_right_limit);
		$this->length_limit = max($this->length_limit, $this->left_limit + $this->right_limit);
		$this->right_limit_last = max($this->right_limit, $this->right_limit_last);
		$this->left_limit_uc = max($this->left_limit, $this->left_limit_uc);
	}


	public function set_limits($left_limit = 0, $right_limit = 0, $length_limit = 0,
	    $right_limit_last = 0, $left_limit_uc = 0) {
    
		$this->left_limit = $left_limit;
		$this->right_limit = $right_limit;
		$this->length_limit = $length_limit;
		$this->right_limit_last = $right_limit_last;
		$this->left_limit_uc = $left_limit_uc;

		$this->check_limits();
	}


	// $instr:	input string
	// $encoding:	input/output encoding
	// $shy:	hyphen symbol or string

	public function hyphenate($instr, $encoding = '', $shy = '&shy;') {

		$this->soft_hyphen = $shy;

		$alph = $this->alphabet. $this->alphabet_uc;
		if (!$encoding || strcasecmp($this->internal_encoding, $encoding) == 0)
			$uni = '';
		else {
			$alph = @iconv($this->internal_encoding, $encoding, $alph);
			$uni = (preg_match('/^utf\-?8$/i', $encoding)) ? 'u' : '';
		}

		$this->check_limits();

		if (!preg_match_all('/(?<!['. $alph. '\x5C])(['. $alph. ']{'. $this->length_limit. ',})('.
			(($uni) ? '\P{L}' : '[^'. $alph. '\w]'). '*[\n\r])?/'. $uni,
			$instr, $matches, PREG_OFFSET_CAPTURE)) return $instr;

		// last word in the stream should be treated as the last word of paragraph
		$matches[2][sizeof($matches[1]) - 1][0] = '1';
		
		$offset = 0;
		$this->io_encoding = $encoding;

		foreach ($matches[1] as $i => $match) {
			$word = $match[0];
			$pos = $match[1];
			$hword = $this -> hyphenate_word($word,
				(isset($matches[2][$i][0]) && $matches[2][$i][0] !== ''));
			$instr = substr_replace($instr, $hword, $pos + $offset, strlen($word));
			$offset += strlen($hword) - strlen($word);
		}

		return $instr;
	}
}

?>
