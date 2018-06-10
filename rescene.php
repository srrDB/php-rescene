<?php 
/**
 * PHP Library to read and edit a .srr file. It reads .srs files.
 * Copyright (c) 2011-2017 Gfy
 *
 * rescene.php is free software, you can redistribute it and/or modify
 * it under the terms of GNU Affero General Public License
 * as published by the Free Software Foundation, either version 3
 * of the License, or (at your option) any later version.
 *
 * You should have received a copy of the the GNU Affero
 * General Public License, along with rescene.php. If not, see
 * http://www.gnu.org/licenses/agpl.html
 *
 * Additional permission under the GNU Affero GPL version 3 section 7:
 *
 * If you modify this Program, or any covered work, by linking or
 * combining it with other code, such other code is not for that reason
 * alone subject to any of the requirements of the GNU Affero GPL
 * version 3.
 */

/*
 * LGPLv3 with Affero clause (LAGPL)
 * See http://mo.morsi.org/blog/node/270
 * rescene.php written on 2011-07-27
 * Last version: 2018-06-10
 *
 * Features:
 *	- process a SRR file which returns:
 *	   - SRR file size.
 *	   - Application name of the tool used to create the SRR file.
 *	   - List of files stored in the SRR.
 *	   - List of RAR volumes the SRR can reconstruct.
 *	   - List of files that are archived inside these RARs.
 *	   - Size of all Recovery Records inside the SRR file.
 *	   - Comments inside SFV files.
 *	   - Warnings when something unusual is found with the SRR.
 *	- Remove a stored file.
 *	- Rename a stored file.
 *	- Add a stored file.
 *	- Read a stored file.
 *	- Extract a stored file.
 *	- Calculate a hash of the SRR based on RAR metadata.
 *	- Sorting of the stored file names.
 *	- process in memory SRR 'file'
 *	- compare two SRR files
 *		- nfo: ignore line endings
 *		- sfv: sort it before comparing and remove comment lines
 *		- rar metadata
 *			-> quick: by hash
 *			-> see what is missing
 *		- other files
 *			-> quick: by hash
 *	- compare SRS files
 *	- Output flag added to indicate if the RARs used compression.
 *	- Support to read SRS files. (AVI/MKV/MP4/WMV/FLAC/MP3)
 *	- Sort stored files inside the SRR.
 *	- OpenSubtitles.org hash support.
 *	- Extract the SRR meta data of a single RAR set
 *
 *	- nfo compare: strip line endings + new line?
 *		Indiana.Jones.And.The.Last.Crusade.1989.PAL.DVDR-DNA
 *
 * List of possible features/todo list:
 *	- process in memory SRR 'file' + other API functions (very low priority)
 *		=> can be done using temp files in memory
 *	- refactor compare SRR
 *	- merge SRRs (Python script exists)
 *	- encryption sanity check
 *	- add paths before the rar files
 *	- detect when SRR is cut/metadata from rars missing
 *		=> hard to do correctly (SFVs subs exist too)
 *	- how to throw errors correctly?
 *	- sorting the list of the stored files by hand
 *	- "Application name found in the middle of the SRR."
 *	  causes hashes to be different
 *	- http://www.srrdb.com/release/details/Race.To.Witch.Mountain.1080p.BluRay.x264-HD1080 (wrong file size)
 *	- http://www.srrdb.com/release/details/NBA.2010.03.02.Pacers.Vs.Lakers.720p.HDTV.x264-BALLS (crc FFFFFFFF)
 *	- http://www.srrdb.com/release/details/Dexter.S01E03.720p.Bluray.x264-ORPHEUS (short crc)
 *	- When renaming a file and only the capitals will be different, a file with the old name is added.
 *	- http://www.srrdb.com/release/details/Scrapland.AlcoholClone.MI-NOGRP (dupe names, so not all files get shown)
 *	- Add error when a file is twice in the SFV (twice the meta data too)
 *	- Add warning when there is no SFV file and the SRR contains RAR meta data
 *
 */

// necessary for storing files in large (60MB) SRR files
ini_set('memory_limit', '512M');

$BLOCKNAME = array(
0x69 => 'SRR VolumeHeader',
0x6A => 'SRR Stored File',
0x6B => 'SRR OSO Hash',
0x6C => 'SRR RAR Padding',
0x71 => 'SRR RAR subblock',
0x72 => 'RAR Marker',
0x73 => 'Archive Header',
0x74 => 'File',
0x75 => 'Old style - Comment',
0x76 => 'Old style - Extra info (authenticity information)',
0x77 => 'Old style - Subblock',
0x78 => 'Old style - Recovery record',
0x79 => 'Old style - Archive authenticity',
0x7A => 'New-format subblock',
0x7B => 'Archive end'
);

class FileType {
	const MKV = 'MKV';
	const AVI = 'AVI';
	const MP4 = 'MP4';
	const WMV = 'WMV';
	const FLAC = 'FLAC';
	const MP3 = 'MP3';
	const STREAM = 'STRM'; // vob and basic m2ts
	const M2TS = 'M2TS';
	const Unknown = '';
}

// for suppressing error messages
$CLI_APP = false;

// cli progs are cool
if (!empty($argc) && strstr($argv[0], basename(__FILE__))) {
	$CLI_APP = true;
	
	/* How to use the CLI version in Windows:
	 - Download and install PHP.  http://windows.php.net/download/
	- Run this script by entering something like
	C:\Program Files (x86)\PHP\php.exe rescene.php
	in the command prompt.
	- [Add 'C:\Program Files (x86)\PHP' to your systems Path environment variable
	to be able to run PHP from anywhere.]
	- To run this script from everywhere, create 'rescene.bat' in a directory that is in your PATH.
	For example: 'C:\Windows\rescene.bat'
	Include the following content:
	"C:\Program Files (x86)\PHP\php.exe" "C:\Windows\rescene.php" %*
	And place the PHP file accordingly.
	Enter 'rescene' anywhere to use it.
	*/
	if (!array_key_exists(1, $argv)) {
		echo "The first parameter needs to be a .srr file.\n";
		echo "  -s 'file to store' (Save)\n";
		echo "  -d 'file to remove' (Delete)\n";
		echo "  -r 'file to rename' (Rename)\n";
		echo "  -v 'file to get' (View)\n";
		echo "  -x 'file to write' (eXtract)\n";
		echo "  -p 'file to split' (sPlit)\n";
		echo "  -h 'special hash of the SRR file' (Hash)\n";
		echo "  -a 'show SRS info (sAmple)\n";
		echo "  -l 'show stored SRR languages (Languages)\n";
		echo "  -c 'compare two SRR files' (Compare)\n";
		echo "  -t 'runs a couple of small tests' (Testing)\n";
		exit(1);
	}
	$srr = $argv[1];

	// to test execution time
	$mtime = microtime();
	$mtime = explode(' ',$mtime);
	$mtime = $mtime[1] + $mtime[0];
	$starttime = $mtime;

	if (array_key_exists(2, $argv)) {
		$switch = $argv[2];
		if (array_key_exists(3, $argv)) {
			$file = $argv[3];
			switch($switch) {
				case '-d': // delete
					if (removeFile($srr, $file)) {
						echo 'File successfully removed.';
					} else {
						echo 'File not found in SRR file.';
					}
					break;
				case '-s': // store
					$path = '';
					if (array_key_exists(4, $argv)) {
						$path = $argv[4];
					}
					if (storeFileCli($srr, $file, $path)) {
						echo 'File successfully stored.';
					} else {
						echo 'Error while storing file.';
					}
					break;
				case '-r': // rename
					if (array_key_exists(4, $argv)) {
						$newName = $argv[4];
						echo 'SRR file: ' . $srr . "\n";
						echo 'Old name: ' . $file . "\n";
						echo 'New name: ' . $newName . "\n";
						if (renameFile($srr, $file, $newName)) {
							echo 'File successfully renamed.';
						} else {
							echo 'Error while renaming file.';
						}
					} else {
						echo 'Please enter a new name.';
					}
					break;
				case '-v': // view
					print_r(getStoredFile($srr, $file));
					break;
				case '-x': // extract
					// strip the path info
					$nopath = basename($file);
					$result = file_put_contents($nopath, getStoredFile($srr, $file));
					if ($result !== FALSE) {
						echo 'File succesfully extracted';
					} else {
						echo 'Something went wrong. Did you provide a correct file name with path?';
					}
					break;
				case '-c': // compare
					print_r(compareSrr($srr, $file));
					break;
				case '-p': // split
					$data = grabSrrSubset($srr, $file);
					file_put_contents('rescene.php_split.srr', $data);
					break;
				default:
					echo 'Unknown parameter. Use -r, -a, -v, -x or -c.';
			}
		} elseif ($switch === '-h') {
			echo 'The calculated content hash for this SRR file is: ';
			$result = processSrr($srr);
			echo calculateHash($srr, $result['rarFiles']);
		} elseif ($switch === '-a') {
			// show SRS info
			$srsData = file_get_contents($srr);
			print_r(processSrsData($srsData));
		} elseif ($switch === '-l') {
			// show vobsub languages
			print_r(getVobsubLanguages($srr));
		} elseif ($switch === '-t') {
			echo 'fileNameCheckTest: ';
			if (fileNameCheckTest()) {
				echo "OK!\n";
			} else {
				echo "NOT OK!\n";
			}
			echo 'createSrrHeaderBlockTest: ';
			if (createSrrHeaderBlockTest()) {
				echo "OK!\n";
			} else {
				echo "NOT OK!\n";
			}
			echo 'getBasenameVolumeTest: ';
			if (getBasenameVolumeTest()) {
				echo "OK!\n";
			} else {
				echo "NOT OK!\n";
			}
			
			//compareSrr($srr, $srr);

			//$data = file_get_contents($srr);
			//print_r(processSrrData($data));
			//add file
			//storeFileCli($srr, 'dbmodel.png');

			//remove file
			//if(removeFile($srr, 'dbmodel.png')) {
			//	  print_r("successfully removed");
			//}

			//process file
//			   if ($result = processSrr($srr)) {
//				   print_r($result);
//				   echo 'success';
//			   } else {
//				   echo 'failure';
//			   }
			
//			   $sf = array_keys($result['storedFiles']);
//			   sort($sf);
//			   if (sortStoredFiles($srr, $sf)) {
//				   echo 'success';
//			   } else {
//				   echo 'failure';
//			   }

		}
	} else {
		$result = processSrr($srr);
		//print_r($result['storedFiles']);
		//print_r(($result['warnings']));
		//print_r(sortStoredFiles($result['storedFiles']));
		print_r($result);
	}

	// end part processing time
	$mtime = microtime();
	$mtime = explode(' ',$mtime);
	$mtime = $mtime[1] + $mtime[0];
	$endtime = $mtime;
	$totaltime = ($endtime - $starttime);
	echo "\nFile processed in {$totaltime} seconds";
}

// API functions
/**
 * Processes a whole SRR file and returns an array with useful details.
 * @param string $file location to the file that needs to be read.
 * @return	mixed  data array, or false on failure
 */
function processSrr($file) {
	$result = FALSE;

	if(file_exists($file)) {
		$fh = fopen($file, 'rb');

		if (flock($fh, LOCK_SH)) {
			$result = processSrrHandle($fh);
			flock($fh, LOCK_UN); // release the lock
		}

		fclose($fh); // close the file
	}

	return $result;
}

/**
 * Processes a whole SRR file and returns an array with useful details.
 * @param resource $srrFileData the contents of the SRR file.
 */
function processSrrData(&$srrFileData) {
	// http://www.php.net/manual/en/wrappers.php.php
	// Set the limit to 5 MiB. After this limit, a temporary file will be used.
	$memoryLimit = 5 * 1024 * 1024;
	$fp = fopen("php://temp/maxmemory:$memoryLimit", 'r+');
	fwrite($fp, $srrFileData);
	rewind($fp);
	$result = processSrrHandle($fp);
	fclose($fp);
	return $result;
}

/**
 * Leaves the file handle open!
 * Only used in the 2 functions above.
 */
function processSrrHandle($fileHandle) {
	// global $BLOCKNAME;
	$fh = $fileHandle;
	$srrSize = getFileSizeHandle($fileHandle);

	// variables to store all resulting data
	$appName = 'No SRR application name found';
	$stored_files = array();
	$rar_files = array();
	$archived_files = array();
	$oso_hashes = array();
	$recovery = NULL;
	$sfv = array();
	$sfv['comments'] = array();
	$sfv['files'] = array();
	$warnings = array();
	$compressed = FALSE; // it's an SRR file for compressed RARs
	$encrypted = FALSE; // encryption is used on one or more files

	// other initializations
	$read = 0; // number of bytes we have read so far
	$last_read = 0; // to prevent looping on encountering bad data
	$current_rar = NULL;
	$customPacker = FALSE; // when not created with WinRAR

	while($read < $srrSize) {
		$add_size = TRUE;

		// to read basic block header
		$block = new Block($fh, $warnings);

		// echo 'Block type: ' . $BLOCKNAME[$block->blockType] . "\n";
		// echo 'Block flags: ' . dechex($block->flags) . "\n";
		// echo 'Header size: ' . $block->hsize . "\n";

		switch($block->blockType) {
			case 0x69: // SRR Header Block
				if ($appName !== 'No SRR application name found') {
					array_push($warnings, 'Application name found in the middle of the SRR.');
				}
				$appName = $block->readSrrAppName();
				break;
			case 0x6B: // SRR OSO Hash (blocks at the end of the file)
				  $block->srrOsoHashFileHeader();
				$entry = array();
				$entry['fileName'] = $block->fileName;
				$entry['osoHash'] = $block->osoHash;
				$entry['fileSize'] = $block->fileSize;
				$entry['blockOffset'] = $block->startOffset;
				$entry['blockSize'] = $block->hsize;
				$entry['data'] = $block->data;
				array_push($oso_hashes, $entry);
				
				if (!is_null($current_rar)) {
					$current_rar = NULL; // SRR block detected: start again
				}
				break;
			case 0x6C: // SRR RAR Padding Block
				$current_rar['fileSize'] -= $block->hsize;
				$block->skipBlock();
				break;
			case 0x6A: // SRR Stored File Block
				$block->srrReadStoredFileHeader();

				// store stored file details
				$sf = array();
				$sf['fileName'] = $block->fileName;
				$sf['fileOffset'] = $block->storedFileStartOffset;
				$sf['fileSize'] = $block->addSize;
				$sf['blockOffset'] = $block->startOffset;

				// The same file can be stored multiple times.
				// This can make SRR files unnoticeably large.
				if (array_key_exists($block->fileName, $stored_files)) {
					// message here must start with Duplicate for the check in sorting
					array_push($warnings, "Duplicate file detected! {$sf['fileName']}");
				}
				if (preg_match('/\\\\/', $block->fileName)) {
					array_push($warnings, "Backslash detected! {$sf['fileName']}");
				}
				if ($block->addSize === 0) {
					// an "empty" directory is allowed
					if (strpos($sf['fileName'], '/') === FALSE) {
						array_push($warnings, "Empty file detected! {$sf['fileName']}");
					}
				} elseif (strtolower(substr($sf['fileName'], - 4)) === '.sfv') {
					// we read the sfv file to grab the crc data of the rar files
					$temp = processSfv(fread($fh, $block->addSize));
					$sfv['comments'] = array_merge($sfv['comments'], $temp['comments']);
					$sfv['files'] = array_merge($sfv['files'], $temp['files']);
					$sf['basenameVolume'] = getBasenameVolume($block->fileName, FALSE);
				}

				$block->skipBlock();
				// calculate CRC of the stored file
				$sdata = stream_get_contents($fileHandle, $block->addSize, $block->storedFileStartOffset);
				$sf['fileCrc'] = strtoupper(str_pad(dechex(crc32($sdata)), 8, '0', STR_PAD_LEFT));
				// $sf['fileCrc'] = dechex(crc32(fread($fh, $block->addSize)));
				// $sf['fileCrc'] = hash('crc32b', fread($fh, $block->addSize));

				$stored_files[$block->fileName] = $sf;
				// end file size counting (_should_ not be necessary for Stored File Block)
				// -> 'ReScene Database Cleanup Script 1.0' SRRs were fixed with 'FireScene Cleanup'
				// (stored files weren't before the first SRR Rar file block)
			case 0x71: // SRR Rar File
				if (!is_null($current_rar)) {
					$current_rar = NULL; // SRR block detected: start again
				}
				// end fall through from SRR Stored File block
				if ($block->blockType == 0x6A) {
					break;
				}

				$add_size = FALSE;

				// read the name of the stored rar file
				$block->srrReadRarFileHeader();
				$recovery_data_removed = $block->flags & 0x1;

				// the hashmap key is only the lower case file name without the path
				// to make it possible to add the CRC data from the SFVs
				$key = strtolower(basename($block->rarName));

				if (array_key_exists($key, $rar_files)) {
					$f = $rar_files[$key];
				} else {
					$f = array(); // array that stores the file details
					$f['fileName'] = $block->rarName; // the path is still stored here
					$f['fileSize'] = 0;
					// when the SRR is build without SFV or the SFV is missing some lines
					$f['fileCrc'] = 'UNKNOWN!';
					// useful for actually comparing srr data
					$f['offsetStartSrr'] = $block->startOffset; // where the SRR block begins
					$f['offsetStartRar'] = ftell($fh); // where the actual RAR headers begin
					// initialize, set later when volume header is available
					$f['basenameVolume'] = '';
				}

				$rar_files[$key] = $f;

				// start counting file size
				$current_rar = $f;
				break;
			case 0x74: // RAR Packed File
				$block->rarReadPackedFileHeader();

				if (array_key_exists($block->fileName, $archived_files)) {
					$f = $archived_files[$block->fileName];
					// FLEET, AVS,... (first and last rar have correct size)
					if ($f['fileSizeStart'] !== $block->fileSize) {
						$customPacker = TRUE;
					}
				} else { // new file found in the archives
					$f = array();
					$f['fileName'] = $block->fileName;
					$f['fileTime'] = date("Y-m-d h:i:s", $block->fileTime);
					$f['compressionMethod'] = $block->compressionMethod;
					$f['fileSizeStart'] = $block->fileSize;

					// file size complexity because of crappy custom packers
					if ($block->fileSize !== 0xffffffffffffffff &&  // 1
						$block->fileSize !== -1 && /* 2) 32 bit php */
						$block->fileSize !== 0xffffffff /* 2) 64 bit php */) {
						// file size normal case
						$f['fileSize'] = $block->fileSize;
					} else {
						$f['fileSize'] = 0;
						// 1) custom RAR packers used: last RAR contains the size
						// Street.Fighter.V-RELOADED or Magic.Flute-HI2U or 0x0007
						if ($block->fileSize == 0xffffffffffffffff) {
							array_push($warnings, "RELOADED/HI2U/0x0007 custom RAR packer detected.");
						}
						// 2) crap group that doesn't store the correct size at all:
						// The.Powerpuff.Girls.2016.S01E08.HDTV.x264-QCF							
						if ($block->fileSize == 0xffffffff || $block->fileSize == -1) {
							array_push($warnings, "Crappy QCF RAR packer detected.");
						}
					}
				}

				// check if compression was used
				if ($f['compressionMethod'] != 0x30) { // 0x30: Storing
					$compressed = TRUE;
				}
				
				// file size counting fixes
				// 2) above int was correct? it must match at the end - QCF
				if (($block->fileSize == -1 || $block->fileSize == 0xffffffff) && !$compressed) {
					$f['fileSize'] += $block->addSize;
				}

				// 1) expected the last RAR (first with the proper value)
				if ($block->fileSize !== 0xffffffffffffffff && $f['fileSize'] == 0) {
					$f['fileSize'] = $block->fileSize;
				}

				// CRC of the file is the CRC stored in the last archive that has the file
				// add leading zeros when the CRC isn't 8 characters
				$f['fileCrc'] = strtoupper(str_pad($block->fileCrc, 8, '0', STR_PAD_LEFT));
				$archived_files[$block->fileName] = $f;
				
				// file is encrypted with password
				// The.Sims.4.City.Living.INTERNAL-RELOADED
				if ($block->flags & 0x4) {
					$encrypted = TRUE;
				}
				break;
			case 0x78: // RAR Old Recovery
				if (is_null($recovery)) {
					// first recovery block we see
					$recovery = array();
					$recovery['fileName'] = 'Protect!';
					$recovery['fileSize'] = 0;
				}
				$recovery['fileSize'] += $block->addSize;
				if ($recovery_data_removed) {
					$block->skipHeader();
				} else { // we need to skip the data that is still there
					$block->skipBlock();
				}
				break;
			case 0x7A: // RAR New Subblock: RR, AV, CMT
				$block->rarReadPackedFileHeader();
				if ($block->fileName === 'RR') { // Recovery Record
					if (is_null($recovery)) {
						$recovery = array();
						$recovery['fileName'] = 'Protect+';
						$recovery['fileSize'] = 0;
					}
					$recovery['fileSize'] += $block->addSize;
					if (!$recovery_data_removed) {
						$block->skipBlock();
					}
					break;
				} // other types have no data removed and will be fully skipped
				$block->skipBlock();
				break;
			case 0x73: // RAR Volume Header
				// warnings for ASAP and IMMERSE -> crappy rars
				$ext = strtolower(substr($current_rar['fileName'], - 4));
				if (($block->flags & 0x0100) && $ext !== '.rar' && $ext !== '.001') {
					array_push($warnings, "MHD_FIRSTVOLUME flag set for {$current_rar['fileName']}.");
				}
				$is_new_style_naming = $block->flags & 0x0010 && $block->flags & 0x0001; // new numbering and a volume
				$current_rar['basenameVolume'] = getBasenameVolume($current_rar['fileName'], $is_new_style_naming);

				// encrypted block headers are used: these SRRs don't exist
				if ($block->flags & 0x0080) {
					$encrypted = TRUE;
				}
			case 0x72: // RAR Marker
			case 0x7B: // RAR Archive End
			case 0x75: // Old Comment
			case 0x76: // Old Authenticity
			case 0x77: // Old Subblock
			case 0x79: // Old Authenticity
				// no usefull stuff for us anymore: skip block and possible contents
				$block->skipBlock();
				break;
			default: // Unrecognized RAR/SRR block found!
				$block->skipBlock();
				if (!empty($current_rar['fileName'])) { // Psych.S06E02.HDTV.XviD-P0W4
					// -> P0W4 cleared RAR archive end block: almost all zeros except for the header length field
					array_push($warnings, "Unknown RAR block found in {$current_rar['fileName']}");
				} else { // e.g. a rar file that still has its contents
					array_push($warnings, 'ERROR: Not a SRR file?');
					return FALSE;
					//trigger_error('Not a SRR file.', E_USER_ERROR);
				}
		}

		// calculate size of the rar file + end offset
		if (!is_null($current_rar)) {
			if ($add_size === TRUE) {
				$current_rar['fileSize'] += $block->fullSize;
			}
			// store end offset of the header data of the rar volume
			$current_rar['offsetEnd'] = ftell($fh);
			// keep the results updated
			$rar_files[strtolower(basename($current_rar['fileName']))] = $current_rar;
		}

		// nuber of bytes we have processed
		$read = ftell($fh);
		
		// don't loop when bad data is encountered
		if ($read === $last_read) {
			break;
		}
		$last_read = $read;
	}

	// add sfv CRCs to all the rar files we have found
	foreach ($sfv['files'] as $key => $val) {
		// the capitalization between sfv and the actual file isn't always the same
		$lkey = strtolower($key);
		if (array_key_exists($lkey, $rar_files)) {
			$rar_files[$lkey]['fileCrc'] = strtoupper($val);
			// everything that stays can not be reconstructed (subs from .sfv files)
			unset($sfv['files'][$key]); // remove data from $sfv
		}
	}
	
	if ($customPacker) {
		array_push($warnings, 'Custom RAR packer detected.');
	}

	// return all info in a multi dimensional array
	return array(
			'srrSize' => $srrSize,
			'appName' => $appName,
			'storedFiles' => $stored_files,
			'rarFiles' => $rar_files,
			'archivedFiles' => $archived_files,
			'osoHashes' => $oso_hashes,
			// Recovery Records across all archives in the SRR data
			// the name is based on the first encountered recovery block
			// Protect! -> old style RAR recovery (before RAR 3.0)
			// Protect+ -> new style RAR recovery
			'recovery' => $recovery,
			'sfv' => $sfv, // comments and files that aren't covered by the SRR
			'warnings' => $warnings, // when something unusual is found
			'compressed' => $compressed,
			'encrypted' => $encrypted
	);
}

/**
 * Same as the getStoredFileData() function, but based on the file name.
 * @param string $srrfile	The name of the SRR file to read.
 * @param string $filename	The file we want the contents from, including the path.
 * @return resource The bytes of the file or FALSE on failure.
 */
function getStoredFile($srrfile, $filename) {
	$result = FALSE;
	$fh = fopen($srrfile, 'rb');
	
	if (flock($fh, LOCK_SH)) {
		$srr = processSrrHandle($fh);
		
		foreach($srr['storedFiles'] as $key => $value) {
			if($key === $filename) {
				$result = stream_get_contents($fh, $value['fileSize'], $value['fileOffset']);
				break;
			}
		}

		flock($fh, LOCK_UN); // release the lock
	}
	
	fclose($fh); // close the file
	return $result;
}

/**
 * Removes a file stored in the SRR file.
 * @param	string	$srrfile	Path of the SRR file.
 * @param	string	$filename	Path and name of the file to remove.
 * @return TRUE on success, FALSE otherwise
 */
function removeFile($srrfile, $filename) {
	$result = FALSE;
	$fh = fopen($srrfile, 'c+b');
	
	if (flock($fh, LOCK_EX)) {
		$srr = processSrrHandle($fh);
	
		foreach ($srr['storedFiles'] as $key => $value) {
			if ($value['fileName'] === $filename) {
				// how much to remove? read the block starting from the offset
				fseek($fh, $value['blockOffset'], SEEK_SET);
				$warnings_stub = array();
				$block = new Block($fh, $warnings_stub);
				fseek($fh, $value['blockOffset'] + $block->fullSize, SEEK_SET);
				$after = fread($fh, $srr['srrSize']); // srrSize: the (max) amount to read
				ftruncate($fh, $value['blockOffset']);
				fseek($fh, 0, SEEK_END); // Upon success, returns 0; otherwise, returns -1. 
				fwrite($fh, $after);
				$result = TRUE;
				break;
			}
		}
	
		flock($fh, LOCK_UN); // release the lock
	}
	
	fclose($fh); // close the file
	return $result;
}

/**
 * Adds a file to the saved files inside a SRR file.
 * @param string	$srr	The path of the SRR file.
 * @param string	$file	The file to store.
 * @param resource	$path	The path that must be prefixed for the file name.
 * @return TRUE on success, FALSE otherwise.
 */
function storeFileCli($srr, $file, $path='') {
	// the path must have the path separator included
	if ($path != '' && substr($path, -1) !== '/') {
		return FALSE;
	}
	$fileContents = file_get_contents($file);
	return storeFile($srr, $path . basename($file), $fileContents);
}

/**
 * Adds a file to the saved files inside a SRR file.
 * @param string	$srrFile	The path of the SRR file.
 * @param string	$filePath	The path and name that will be stored.
 * @param resource	$fdata		The bytes of the file to store in the SRR file.
 * @return TRUE when storing succeeds.
 */
function storeFile($srrFile, $filePath, $fdata) {
	// check for illegal windows characters
	// the path separator must be /
	// twice (//) may not be possible
	if (fileNameCheck($filePath)) {
		return FALSE;
	}

	$fh = fopen($srrFile, 'c+b');
	
	if (flock($fh, LOCK_EX)) {
		$srr = processSrrHandle($fh);
		
		// don't let the same file get added twice
		foreach($srr['storedFiles'] as $key => $value) {
			if($key === $filePath) {
				flock($fh, LOCK_UN);
				fclose($fh);
				return FALSE;
			}
		}
	
		$offset = newFileOffset($fh);
		if ($offset < 0) {
			// broken/empty .srr file due to bugs :(
			flock($fh, LOCK_UN);
			fclose($fh);
			return FALSE;
		}
		
		$after = fread($fh, $srr['srrSize']);
		$header = createStoredFileHeader($filePath, strlen($fdata));
		ftruncate($fh, $offset);
		fseek($fh, 0, SEEK_END); // Upon success, returns 0; otherwise, returns -1.
		fwrite($fh, $header);
		fwrite($fh, $fdata);
		fwrite($fh, $after);
	
		flock($fh, LOCK_UN); // release the lock
	}
	
	fclose($fh); // close the file
	return TRUE;
}

function addOsoHash($srrFile, $oso_hash_data) {
	$fh = fopen($srrFile, 'c+b');
	
	if (flock($fh, LOCK_EX)) {
		$result = processSrrHandle($fh);
		
		// the hash must not already exist
		foreach($result['osoHashes'] as $value) {
			if ($value['data'] == $oso_hash_data) {
				flock($fh, LOCK_UN);
				fclose($fh);
				return FALSE;
			}
		}
		
		fseek($fh, 0, SEEK_END); // is not necessary
		fwrite($fh, $oso_hash_data);
	
		flock($fh, LOCK_UN); // release the lock
	}
	
	fclose($fh); // close the file
	return TRUE;
}

// /**
//	* Adds a new OSO hash to the end of the SRR file.
//	* 
//	* @param string $srr
//	* @param int $fileSize
//	* @param string $osoHash
//	* @param string $fileName
//	*/
// function addOsoHash($srr, $fileSize, $osoHash, $fileName) {
//	   // check for illegal windows characters; no paths
//	   if (fileNameCheck($fileName) || strstr($fileName, '/')) {
//		   return FALSE;
//	   }
//	   if ($fileSize < 0 || !preg_match('/[a-f0-9]{16}/i', $osoHash) || strlen($fileName) < 1) {
//		   return FALSE;
//	   }
	
//	   // the hash must not already exist
//	   $result = processSrr($srr);
//	   foreach($result['osoHashes'] as $value) {
//		   if ($value['fileName'] == $fileName &&
//			   $value['fileSize'] == $fileSize &&
//			   $value['osoHash'] == $osoHash) {
//			   return FALSE;
//		   }
//	   }
	
//	   $fh = fopen($srr, 'rb');
//	   $before = fread($fh, getFileSizeHandle($fh));
//	   fclose($fh);
	
//	   // 2 byte CRC, 1 byte block type, 2 bytes for the flag 0x0000
//	   $header = pack('H*' , '6B6B6B0000');
	
//	   $osoBlockHeader = encode_int($fileSize); // broken on 32 bit!!
//	   // OSO hash stored as little endian
//	   $reversed = '';
//	   for($i=strlen($osoHash);$i>=0;$i-=2) {
//		   $reversed .= substr($osoHash, $i, 2);
//	   }
//	   $osoBlockHeader .= pack('H*' , $reversed);
//	   $osoBlockHeader .= pack('v', strlen($fileName));
//	   $osoBlockHeader .= $fileName;
//	   $headerSize = pack('v', 5 + 2 + 8 + 8 + 2 + strlen($fileName));

//	   print_r(unpack('H*', $header . $headerSize . $osoBlockHeader));
//	   //file_put_contents($srr, $before . $header . $headerSize . $osoBlockHeader, LOCK_EX);
//	   return TRUE;
// }

// function encode_int($in, $pad_to_bits=64, $little_endian=true) {
//	   $in = decbin($in);
//	   $in = str_pad($in, $pad_to_bits, '0', STR_PAD_LEFT);
//	   $out = '';
//	   for ($i = 0, $len = strlen($in); $i < $len; $i += 8) {
//		   $out .= chr(bindec(substr($in,$i,8)));
//	   }
//	   if($little_endian) $out = strrev($out);
//	   return $out;
// }

/**
 * Renames a stored file.
 * @param string $srrFile The path of the SRR file.
 * @param string $oldName The path and file name of a stored file.
 * @param string $newName The new path and file name of a stored file.
 * @return TRUE on success, FALSE otherwise.
 */
function renameFile($srrFile, $oldName, $newName) {
	if (fileNameCheck($newName)) {
		if ($CLI_APP) {
			print_r("The new file name is illegal. Use only forward slashes for paths.\n");
		}
		return FALSE;
	}
	
	$result = FALSE;
	$fh = fopen($srrFile, 'c+b');
	
	if (flock($fh, LOCK_EX)) {
		$srr = processSrrHandle($fh);
		
		// prevent renaming to a file that already exists
		foreach ($srr['storedFiles'] as $key => $value) {
			if ($key === $newName) {
				flock($fh, LOCK_UN);
				fclose($fh);
				return FALSE;
			}
		}
		 
		// rename the first file
		foreach ($srr['storedFiles'] as $key => $value) {
			if ($value['fileName'] === $oldName) {
				fseek($fh, $value['blockOffset'], SEEK_SET);
				$warnings_stub = array();
				$block = new Block($fh, $warnings_stub);
				$block->srrReadStoredFileHeader();
				fseek($fh, $value['blockOffset'] + $block->hsize, SEEK_SET);
				$after = fread($fh, $srr['srrSize']); // srrSize: the (max) amount to read
				ftruncate($fh, $value['blockOffset']);
				fseek($fh, 0, SEEK_END); // Upon success, returns 0; otherwise, returns -1.
				$changedHeader = createStoredFileHeader($newName, $block->addSize);
				fwrite($fh, $changedHeader);
				fwrite($fh, $after);
				$result = TRUE;
				break;
			}
		}
		
		flock($fh, LOCK_UN); // release the lock
	}
	
	fclose($fh); // close the file
	return $result;
}

/**
 * Calculate hash to identify SRRs that cover the same RAR volumes.
 * The result can be wrong when the provided $rarFiles array is outdated.
 * @param string $srr The SRR file.
 * @param array $rarFiles The resulting array from processSrr().
 * @return string Sha1 hash of the srr file
 */
function calculateHash($srrfile, $rarFiles, $algorithm='sha1') {
	// do the calculation only on the sorted RAR volumes
	// this way it still yields the same result if the order of creation differs
	uasort($rarFiles, 'rarFileCmp'); // sort on filename without path, case insensitive
	// compared with pyReScene when capitals are used: same behavior
	// Parlamentet.S06E02.SWEDiSH-SQC
	$hashContext = hash_init($algorithm);
	$fh = fopen($srrfile, 'rb');
	
	if (flock($fh, LOCK_SH)) {
		// calculate hash only on the RAR metadata
		foreach ($rarFiles as $key => $value) {
			$start = $value['offsetStartRar'];
			$end = $value['offsetEnd'];
			$data = stream_get_contents($fh, ($end - $start), $start);
			hash_update($hashContext, $data);
		}

		flock($fh, LOCK_UN); // release the lock
	}
	
	fclose($fh); // close the file
	return hash_final($hashContext);
}

// Comparison function
function rarFileCmp($a, $b) {
	if ($a['fileName'] == $b['fileName']) {
		return 0;
	}
	return (strtolower($a['fileName']) < strtolower($b['fileName'])) ? -1 : 1;
}

function calculateHashHandle($srrHandle, $rarFiles, $algorithm='sha1') {
	// do the calculation only on the sorted RAR volumes
	// this way it still yields the same result if the order of creation differs
	uasort($rarFiles, 'rarFileCmp'); // sort on filename without path, case insensitive
	$hashContext = hash_init($algorithm);

	// calculate hash only on the RAR metadata
	foreach ($rarFiles as $key => $value) {
		$start = $value['offsetStartRar'];
		$end = $value['offsetEnd'];
		$data = stream_get_contents($srrHandle, ($end - $start), $start);
		hash_update($hashContext, $data);
	}
	return hash_final($hashContext);
}

function calculateHashString($srrData, $rarFiles, $algorithm='sha1') {
	// do the calculation only on the sorted RAR volumes
	// this way it still yields the same result if the order of creation differs
	uasort($rarFiles, 'rarFileCmp'); // sort on filename without path, case insensitive
	$hashContext = hash_init($algorithm);

	// calculate hash only on the RAR metadata
	foreach ($rarFiles as $key => $value) {
		$start = $value['offsetStartRar'];
		$end = $value['offsetEnd'];

		$memoryLimit = 5 * 1024 * 1024;
		$fp = fopen("php://temp/maxmemory:$memoryLimit", 'r+');
		fputs($fp, $srrData);
		rewind($fp);
		$fileAttributes = fstat($fp);

		$data = stream_get_contents($fp, ($end - $start), $start);
		hash_update($hashContext, $data);
	}
	return hash_final($hashContext);
}

/**
 * Compare 2 SRR files and list the differences.
 * @param string $one First SRR file path.
 * @param string $two Second SRR file path.
 * @return array Some complicated array with differences.
 */
function compareSrr($one, $two) {
	$result = FALSE;
	$fho = fopen($one, 'rb');
	$fht = fopen($two, 'rb');
	
	if (flock($fho, LOCK_SH) && flock($fht, LOCK_SH)) {
		$rone = processSrrHandle($fho);
		$rtwo = processSrrHandle($fht);
		
		$result = compareSrrRaw($rone, $rtwo, $fho, $fht);
	
		flock($fho, LOCK_UN); // release the lock
		flock($fht, LOCK_UN);
	}
	
	fclose($fho); // close the files
	fclose($fht); 
	
	return $result;
}

/**
 * Same as above, but the info arrays of the SRR files were read before.
 * 2 times less parsing of the SRR files.
 */
function compareSrrRaw($rone, $rtwo, $fhone, $fhtwo) {
	$hashOne = calculateHashHandle($fhone, $rone['rarFiles']);
	$hashTwo = calculateHashHandle($fhtwo, $rtwo['rarFiles']);

	// ----- The RARs -----
	// rebuild data can be considered the same?
	$sameRarData = $hashOne === $hashTwo;

	// hash => file name
	$hashesOne = hashParts($fhone, $rone['rarFiles']);
	$hashesTwo = hashParts($fhtwo, $rtwo['rarFiles']);

	// hash => file name (of those names unique to the first array)
	$left = array_diff($hashesOne, $hashesTwo);
	$right = array_diff($hashesTwo, $hashesOne);

	if ($sameRarData && count(array_merge($left, $right)) === 0) {
		$sameRarNames = TRUE;
	} else {
		$sameRarNames = FALSE;
		// must be picked in the comparison as the other one doesn't have it
		$uniqueRarOne = array_values(array_diff_key($hashesOne, $hashesTwo));
		$uniqueRarTwo = array_values(array_diff_key($hashesTwo, $hashesOne));

		// of the ones that are the same, the best name should be picked by default
		$twiceHash = array_keys(array_intersect_key($left, $right));
		$namesRarOne = array();
		$namesRarTwo = array();

		foreach ($twiceHash as $value) {
			$l = $left[$value];
			$r = $right[$value];

			// heuristic: we want the one with the longest length
			// this one probably has a path added
			if (strlen($l) > strlen($r)) {
				array_push($namesRarOne, $l);
			} else {
				array_push($namesRarTwo, $r);
			}
		}
	}

	// ----- The stored files -----
	// we compare .nfo, .sfv, .srs and other files to check if they are the same
	// or not a notewhorthy difference (line endings, sfv comments, ...)
	// if they are the same, only the filename/path needs to be chosen
	$filesOne = $rone['storedFiles'];
	$filesTwo = $rtwo['storedFiles'];

	// same name, same data => OK
	// different name, same data => one of both probably has a bad name (paths should always be the same for nfos)
	$same = array(); // list of tuples (fileOne, fileTwo, best) (because they can have different names)
	$sameName = array(); // same name, different data => e.g. Mr.X and Mr.Y sitescripts banner added for NFOs
	// suggest the largest file?

	// different name, different data => nfos from fixes ect.
	$uniqueOne = $filesOne;
	$uniqueTwo = $filesTwo;


	// *** NFO ***
	$oneNfo = getFilesByExt($filesOne, '.nfo');
	$twoNfo = getFilesByExt($filesTwo, '.nfo');
	// do not process these files again
	// Returns an array containing all the values from array1 that are not present in any of the other arrays.
	$filesOne = array_diff_assoc($filesOne, $oneNfo);
	$filesTwo = array_diff_assoc($filesTwo, $twoNfo);
	$oneNfo = addNfoHash($oneNfo, $fhone);
	$twoNfo = addNfoHash($twoNfo, $fhtwo);

	foreach ($oneNfo as $okey => $ovalue) {
		foreach ($twoNfo as $tkey => $tvalue) {
			$toUnset = FALSE;
			if ($ovalue['hash'] === $tvalue['hash']) {
				array_push($same, array($okey, $tkey,
				'lines1' => $ovalue['lines'], 'lines2' => $tvalue['lines']));
				$toUnset = TRUE;
			} elseif ($ovalue['fileName'] === $tvalue['fileName']) {
				// suggest the largest NFO file
				if ($ovalue['fileSize'] > $tvalue['fileSize']) {
					$best = 0;
				} else {
					$best = 1;
				}
				array_push($sameName, array($okey, $tkey, 'best' => $best,
				'lines1' => $ovalue['lines'], 'lines2' => $tvalue['lines']));
				$toUnset = TRUE;
				// TODO: show text diff?
			}
			if ($toUnset) {
				// remove from the array
				unset($uniqueOne[$okey]);
				unset($uniqueTwo[$tkey]);
			}
		}
	}

	// *** SFV ***
	$oneSfv = getFilesByExt($filesOne, '.sfv');
	$twoSfv = getFilesByExt($filesTwo, '.sfv');
	// do not process these files again
	$filesOne = array_diff_assoc($filesOne, $oneSfv);
	$filesTwo = array_diff_assoc($filesTwo, $twoSfv);
	$oneSfv = addSfvInfo($oneSfv, $fhone);
	$twoSfv = addSfvInfo($twoSfv, $fhtwo);

	foreach ($oneSfv as $okey => $ovalue) {
		foreach ($twoSfv as $tkey => $tvalue) {
			$toUnset = FALSE;
			if ($ovalue['files'] === $tvalue['files']) {
				// suggest the SFV file with the most comments
				if (count($ovalue['comments']) > count($tvalue['comments'])) {
					$best = 0;
				} elseif (count($ovalue['comments']) < count($tvalue['comments'])) {
					$best = 1;
				} else {
					// SFV with the longest file name has probably path info
					if (strlen($ovalue['fileName']) > strlen($tvalue['fileName'])) {
						$best = 0;
					} else {
						$best = 1;
					}
				}
				array_push($same, array($okey, $tkey, 'best' => $best));
				$toUnset = TRUE;
			} elseif ($ovalue['fileName'] === $tvalue['fileName']) {
				array_push($sameName, array($okey, $tkey));
				$toUnset = TRUE;
			}
			if ($toUnset) {
				unset($uniqueOne[$okey]);
				unset($uniqueTwo[$tkey]);
			}
		}
	}

	// *** SRS ***
	$oneSrs = getFilesByExt($filesOne, '.srs');
	$twoSrs = getFilesByExt($filesTwo, '.srs');
	// do not process these files again
	$filesOne = array_diff_assoc($filesOne, $oneSrs);
	$filesTwo = array_diff_assoc($filesTwo, $twoSrs);
	$oneSrs = addSrsInfo($oneSrs, $fhone);
	$twoSrs = addSrsInfo($twoSrs, $fhtwo);

	//print_r($oneSrs);
	//print_r($twoSrs);
	foreach ($oneSrs as $okey => $ovalue) {
		foreach ($twoSrs as $tkey => $tvalue) {
			$toUnset = FALSE;
			// sample name and crc32 must be the same to be the same sample
			if ($ovalue['fileData']->name === $tvalue['fileData']->name &&
					$ovalue['fileData']->crc32 === $tvalue['fileData']->crc32) {
				// checked against main movie file
				if ($ovalue['trackData'][1]->matchOffset === $tvalue['trackData'][1]->matchOffset) {
					// equal enough
					array_push($same, array($okey, $tkey));
					$toUnset = TRUE;
				} else {
					// -c parameter difference
					// indicate which one had the -c parameter used
					if ($ovalue['trackData'][1]->matchOffset != 0) {
						$best = 0;
					} elseif ($tvalue['trackData'][1]->matchOffset != 0) {
						$best = 1;
					} else {
						// suggest longest file name
						if (strlen($ovalue['fileName']) > strlen($tvalue['fileName'])) {
							$best = 0;
						} else {
							$best = 1;
						}
					}
					array_push($sameName, array($okey, $tkey, 'best' => $best));
					$toUnset = TRUE;
				}
			}
			if ($toUnset) {
				unset($uniqueOne[$okey]);
				unset($uniqueTwo[$tkey]);
			}
		}
	}

	// *** OTHER ***
	foreach ($filesOne as $okey => $ovalue) {
		foreach ($filesTwo as $tkey => $tvalue) {
			$toUnset = FALSE;
			// same CRC: exactly the same
			if ($ovalue['fileCrc'] === $tvalue['fileCrc']) {
				array_push($same, array($okey, $tkey));
				$toUnset = TRUE;
				// they only have the same name
			} elseif ($ovalue['fileName'] === $tvalue['fileName']) {
				array_push($sameName, array($okey, $tkey));
				$toUnset = TRUE;
			}
			if ($toUnset) {
				unset($uniqueOne[$okey]);
				unset($uniqueTwo[$tkey]);
			}
		}
	}

	$result = array(
			'sameRarData' => $sameRarData,
			'sameRarNames' => $sameRarNames,

			'same' => $same,
			'sameName' => $sameName,
			'uniqueOne' => array_keys($uniqueOne),
			'uniqueTwo' => array_keys($uniqueTwo),
	);
	if (!$sameRarNames) {
		// these 4 lists cover all unique RAR metadata
		$result = array_merge($result, array(
				'uniqueRarOne' => $uniqueRarOne, // RAR files that are new
				'uniqueRarTwo' => $uniqueRarTwo,
				'namesRarOne' => $namesRarOne, // the RAR names that are better (when content is the same)
				'namesRarTwo' => $namesRarTwo, // these should be picked by default when mergeing
		));
	}
	return $result;
}

function getFilesByExt($fileList, $extension) {
	$result = array();

	foreach ($fileList as $key => $value) {
		if (strtolower(substr($value['fileName'], - 4)) === $extension) {
			$result[$key] = $value;
		}
	}
	return $result;
}

function addNfoHash($list, $fileHandle) {
	foreach($list as $key => $value) {
		// store nfo hash next to the other stored file data
		$nfoData = stream_get_contents($fileHandle, $value['fileSize'], $value['fileOffset']);
		$list[$key]['hash'] = nfoHash($nfoData);
		// check for which nfo has the fewest lines -> probably no unnessesary white lines
		// Indiana.Jones.And.The.Last.Crusade.1989.PAL.DVDR-DNA
		$list[$key]['lines'] = count(explode("\n", $nfoData));
	}
	return $list;
}

function addSfvInfo($list, $fileHandle) {
	foreach($list as $key => $value) {
		$result = processSfv(stream_get_contents($fileHandle, $value['fileSize'], $value['fileOffset']));
		$list[$key]['comments'] = $result['comments'];
		$list[$key]['files'] = $result['files'];
	}
	return $list;
}

function addSrsInfo($list, $fileHandle) {
	foreach($list as $key => $value) {
		$result = processSrsData(stream_get_contents($fileHandle, $value['fileSize'], $value['fileOffset']));
		//print_r($result);
		$list[$key] += $result;
	}
	return $list;
}

function nfoHash($nfoData) {
	// ignore all new lines
	$string = preg_replace("/(\r\n|\r|\n)/", '', $nfoData);
	// trailing whitespace can be stripped sometimes too
	$string = rtrim($string);
	return md5($string);
}

/**
 * Merge two SRR files by selecting the wanted data parts from each of them.
 * @param string $one First SRR file.
 * @param string $two Second SRR file.
 */
function mergeSrr($one, $two, $storeOne, $storeTwo, $rarOne, $rarTwo, $result) {
	$rone = processSrr($one);
	$rtwo = processSrr($two);




}

function processSrsData(&$srsFileData) {
	// http://www.php.net/manual/en/wrappers.php.php
	// Set the limit to 5 MiB. After this limit, a temporary file will be used.
	$memoryLimit = 5 * 1024 * 1024;
	$fp = fopen("php://temp/maxmemory:$memoryLimit", 'r+');
	fputs($fp, $srsFileData);
	rewind($fp);
	$fileAttributes = fstat($fp);
	return processSrsHandle($fp, $fileAttributes['size']);
}

/**
 * Parses an SRS file.
 * @param $fileHandle
 * @param int $srsSize
 * @return array info array
 */
function processSrsHandle($fileHandle, $srsSize) {
	$result = null;
	switch(detectFileFormat($fileHandle)) {
		case FileType::AVI:
			$result = parse_srs_avi($fileHandle, $srsSize);
			break;
		case FileType::MKV:
			$result = parse_srs_mkv($fileHandle, $srsSize);
			break;
		case FileType::MP4:
			$result = parse_srs_mp4($fileHandle, $srsSize);
			break;
		case FileType::WMV:
			$result = parse_srs_wmv($fileHandle, $srsSize);
			break;
		case FileType::FLAC:
			$result = parse_srs_flac($fileHandle, $srsSize);
			break;
		case FileType::MP3:
			$result = parse_srs_mp3($fileHandle, $srsSize);
			break;
		case FileType::STREAM:
			$result = parse_srs_stream($fileHandle, $srsSize);
			break;
		default:
			global $CLI_APP;
			if ($CLI_APP) { // don't show the message when used as library
				echo 'SRS file type not detected';
			}
			break;
	}
	fclose($fileHandle);
	return $result;
}

/**
 * Sorts the stored files in $srrFile according to $sortedFileNameList.
 * @param string $srrFile: path to the SRR file
 * @param array $sortedFileNameList: simple array with file names
 * @return bool success status
 */
function sortStoredFiles($srrFile, $sortedFileNameList) {
	$fh = fopen($srrFile, 'c+b');
	
	if (flock($fh, LOCK_EX)) {
		$srrInfo = processSrrHandle($fh);

		// not the same amount of elements: bad input
		if (count($srrInfo['storedFiles']) != count($sortedFileNameList) ||
			count(preg_grep("/^Duplicate/", $srrInfo['warnings'])) > 0) {
			flock($fh, LOCK_UN);
			fclose($fh);
			return FALSE;
		}
		
		$before = $srrInfo['srrSize'];
		$after = 0;
		// check if each name is the same in both lists
		foreach ($srrInfo['storedFiles'] as $key => $value) {
			if (array_search($key, $sortedFileNameList) === FALSE) {
				flock($fh, LOCK_UN);
				fclose($fh);
				return FALSE;
			}
		
			// offsets where the stored files start and end
			if ($value['blockOffset'] < $before) {
				$before = $value['blockOffset'];
			}
			$offset = $value['fileOffset'] + $value['fileSize'];
			if ($offset > $after) {
				$after = $offset;
			}
		}
		
		fseek($fh, 0, SEEK_SET);
		$beforeData = fread($fh, $before);
		fseek($fh, $after, SEEK_SET);
		$afterData = fread($fh, $srrInfo['srrSize']); // srrSize: the (max) amount to read
		
		// sort the files and grab their blocks
		$between = '';
		foreach ($sortedFileNameList as $key) {
			$bo = $srrInfo['storedFiles'][$key]['blockOffset'];
			$fo = $srrInfo['storedFiles'][$key]['fileOffset'];
			$fs = $srrInfo['storedFiles'][$key]['fileSize'];
			$blockSize = ($fo + $fs) - $bo;
			fseek($fh, $bo, SEEK_SET);
			$between .= fread($fh, $blockSize);
		}
		
		$bytesWritten = 0;
		fseek($fh, 0, SEEK_SET);
		$bytesWritten += fwrite($fh, $beforeData);
		$bytesWritten += fwrite($fh, $between);
		$bytesWritten += fwrite($fh, $afterData);
	
		flock($fh, LOCK_UN); // release the lock
		fclose($fh); // close the file
		return assert($bytesWritten === $srrInfo['srrSize']);
	}
	
	fclose($fh); // close the file
	return TRUE;
}

/**
 * Returns the data of an SRR file that only contains the SFV and
 * the RAR meta data of a certain RAR set. Capitals are ignored for $volume.
 * @param string $srrFile
 * @param string $volume
 * @param string $applicationName
 * @return string
 */
function grabSrrSubset($srrFile, $volume, $applicationName = 'rescene.php partial SRR file') {
	$result = '';
	$fh = fopen($srrFile, 'rb');
	
	if (flock($fh, LOCK_SH)) {
		$srrInfo = processSrrHandle($fh);
		$volume = strtolower($volume);
		
		// 1) construct SRR file header
		$result = createSrrHeaderBlock($applicationName);
		
		// 2) add the right SFV file
		foreach ($srrInfo['storedFiles'] as $key => $value) {
			if (strtolower(substr($key, -4)) === '.sfv' &&
					strtolower($value['basenameVolume']) === $volume) {
				$length = $value['fileSize'] + ($value['fileOffset'] - $value['blockOffset']);
				$result .= stream_get_contents($fh, $length, $value['blockOffset']);
			}
		}
		
		// 3) add the right RAR meta data
		foreach ($srrInfo['rarFiles'] as $key => $value) {
			if (strtolower($value['basenameVolume']) === $volume) {
				$length = $value['offsetEnd'] - $value['offsetStartSrr'];
				$result .= stream_get_contents($fh, $length, $value['offsetStartSrr']);
			}
		}
		
		// 4) ignore everything else
		
		flock($fh, LOCK_UN); // release the lock
	}
	
	fclose($fh); // close the file
	return $result;
}

/**
 * Retrieve and parse the vobsubs languages
 * @param string $srrFile
 * @param array $srrInfo
 * @return array with languages structure
 */
function getVobsubLanguages($srrFile, $srrInfo = null) {
	$languages = array();
	$fh = fopen($srrFile, 'rb');

	if (flock($fh, LOCK_SH)) {
		// avoid parsing SRR again when parameter is provided
		if ($srrInfo === null) {
			$srrInfo = processSrrHandle($fh);
		}

		// get a list of all stored .srr files with languages.diz in them
		$dizFiles = array();
		foreach ($srrInfo['storedFiles'] as $key => $value) {
			if (substr($key, -4) === '.srr') {
				$storedSrr = stream_get_contents($fh, $value['fileSize'], $value['fileOffset']);
				if (strpos($storedSrr, 'languages.diz') !== FALSE) {
					$vobsubSrr = processSrrData($storedSrr);
					$lv = $vobsubSrr['storedFiles']['languages.diz']; // can fail in theory
					$dizFiles[$key] = substr($storedSrr, $lv['fileOffset'], $lv['fileSize']);
				}
			}
		}

		flock($fh, LOCK_UN); // release the lock

		foreach ($dizFiles as $srrVobsubName => $dizData) {
			$languages[$srrVobsubName] = parseLanguagesDiz($dizData);
		}
	}

	fclose($fh); // close the file
	return $languages;
}

// Private helper functions -------------------------------------------------------------------------------------------

function parseLanguagesDiz($data) {
	$idx = array();
	$lastFileName = 'NO IDX FILE NAME DETECTED';

	$lines = preg_split('/$\R?^/m', $data); // early .srr files are not just \n
	foreach ($lines as $line) {
		if (substr($line, 0, 1) === '#') {
			// new idx file
			$lastFileName = substr($line, 2);
			$idx[$lastFileName] = array();
		} else {
			// new language line
			preg_match('/id: ([a-z]{2}).*?,.*/i', $line, $matches);
			if ($matches) { // '--' gets skipped e.g. Zero.Days.2016.PROPER.DVDRip.x264-WiDE
				array_push($idx[$lastFileName], $matches[1]);
			}
		}
	}

	return $idx;
}

/**
 * No locking occurs.
 * @param resource $fileHandle
 * @return integer The size of the file.
 */
function getFileSizeHandle($fileHandle) {
	// PHP uses caching for filesize() and we do not always want that!
	$stat = fstat($fileHandle);
	return $stat['size'];
}

function isFolder($dir) {
	return (strpos($dir, '/', 1) !== FALSE);
}

/**
 * Removes the path and extension information
 * so the common volume name stays.
 * Returns file name upon failure.
 * @param string $pathVolumeName
 */
function getBasenameVolume($pathVolumeName, $new_numbering) {
	// Doctor.Who.The.Enemy.Of.The.World.S05E18.DVDRip.x264-PFa
	// pfa-dw.s05e18.teotw.part02.rar
	// pfa-dw.s05e18.teotw.part02.r00
	$pattern = "/(.*?)(\.rar|\.\d{3}|\.[r-z]\d{2}|\.sfv)$/i";
	if ($new_numbering) {
		$pattern = "/(.*?)(\.part\d+\.rar|\.rar|\.\d{3}|\.[r-z]\d{2}|\.sfv)$/i";
	}
	$fileName = basename($pathVolumeName);
	$matches = Array();
	if (preg_match($pattern, $fileName, $matches)) {
		return $matches[1]; 
	} else {
		return $fileName; // strange case that shouldn't happen
	}
}

function getBasenameVolumeTest() {
	return (getBasenameVolume("i.didnt.know.i.was.pregnant.s04e02.hdtv.xvid-crimson.part01.rar", TRUE)
			== "i.didnt.know.i.was.pregnant.s04e02.hdtv.xvid-crimson" &&
			getBasenameVolume("pfa-dw.s05e18.teotw.part02.rar", FALSE)
			== "pfa-dw.s05e18.teotw.part02" &&
			getBasenameVolume("pfa-dw.s05e18.teotw.part02.r00", FALSE)
			== "pfa-dw.s05e18.teotw.part02");
}

/**
 * Constructs a byte string of the SRR Header Block
 * @param string $applicationName
 * @return string
 */
function createSrrHeaderBlock($applicationName) {
	$nameLength = strlen($applicationName);
	return pack('H*vv', '6969690100', 7 + 2 + $nameLength, $nameLength) . $applicationName;
}

function createSrrHeaderBlockTest() {
	$expected = pack('H*', '696969010016000D00707952655363656E6520302E35');
	$actual = createSrrHeaderBlock('pyReScene 0.5');
	return $expected === $actual;
}

/**
 * No illegal Windows characters.
 * No \ as path separator.
 * No // (double forward slashes).
 * The string cannot start with a /.
 * The string must contain at least one character.
 * The string cannot start or end with a space.
 * The string cannot end with a slash.
 */
function fileNameCheck($path) {
	return preg_match('/([\\\\:*?"<>|]|\/\/)|.*\/$|^\/|^$|^ .*|^.* $/', $path);
}

function fileNameCheckTest() {
	return (!fileNameCheck('ok.ext') &&
			fileNameCheck('dir\file.ext') &&
			fileNameCheck('dir/file:file.ext') &&
			fileNameCheck('dir/file*.ext') &&
			fileNameCheck('dir/file?.ext') &&
			fileNameCheck('dir/file".ext') &&
			fileNameCheck('dir/file<.ext') &&
			fileNameCheck('dir/file>.ext') &&
			fileNameCheck('dir/file|.ext') &&
			fileNameCheck('dir//file.ext') &&
			fileNameCheck('dir\\\\file.ext') &&
			fileNameCheck('/dir/file.ext') &&
			fileNameCheck('') &&
			fileNameCheck('something\\') &&
			fileNameCheck('something/') &&
			fileNameCheck('dir\\file.ext') &&
			fileNameCheck(' dir/file.ext') &&
			fileNameCheck('dir/file.ext '));
}

/**
 * Hash all RAR metadata parts of a SRR file.
 * @param resource $fileHandle The SRR file handle.
 * @param array $rarFiles Subarray result from processSrr().
 */
function hashParts($fileHandle , $rarFiles) {
	$hashes = array();
	foreach($rarFiles as $key => $value) {
		$start = $value['offsetStartRar'];
		$end = $value['offsetEnd'];
		$hash = sha1(stream_get_contents($fileHandle, ($end - $start), $start));
		$hashes[$hash] = $value['fileName'];
	}
	return $hashes;
}

/**
 * Construct the header of a SRR stored file block.
 * @param string $name The path (including file name) of the file to store.
 * @param int $fileSize The size of the stored file.
 */
function createStoredFileHeader($name, $fileSize) {
	// 2 byte CRC, 1 byte block type, 2 bytes for the flag 0x8000: addsize field is present
	$header = pack('H*' , '6A6A6A0080');
	$addSize = pack('V', $fileSize);
	$pathLength = pack('v', strlen($name));
	$headerSize = pack('v', 5 + 2 + 4 + 2 + strlen($name));
	return $header . $headerSize . $addSize . $pathLength . $name;
}

/**
 * We choose the offset to insert a new file to be after the SRR Header Block
 * to keep all files always at the front and to anticipate new SRR blocks.
 * Pointer will be at the position of the return int.
 * @param resource $fileHandle	File handle to the SRR file.
 */
function newFileOffset($fileHandle) {
	fseek($fileHandle, 0, SEEK_SET);
	// read SRR Header Block
	$warnings_stub = array();
	$block = new Block($fileHandle, $warnings_stub);
	if ($block->blockType === 0x69) {
		$block->readSrrAppName();
	} else {
		return -1;
	}
	$offset = ftell($fileHandle);
	return $offset;
}

/**
 * Returns an array with comments and files
 * @param resource $data Binary datastream of SFV file.
 */
function processSfv($data) {
	// create array of all the lines
	$lines = preg_split("/\r\n|\n|\r/", $data);
	$result = array();
	$result['comments'] = array();
	$result['files'] = array();

	for ($i=0; $i < count($lines); $i++) {
		$line = trim($lines[$i]);
		$lineLength = strlen($line);

		// process if line has contents
		if ($lineLength > 0) {
			// the line is a comment line or it is too short
			if (in_array($line[0], array(';')) or $lineLength < 10) {
				array_push($result['comments'], $line);
			} else {
				// parse SFV line
				$spaceIndex = strrpos($line, ' ');
				// strip multiple spaces/tabs in between
				$fileName = rtrim(substr($line, 0, $spaceIndex));
				// http://www.traction-software.co.uk/sfvchecker/ are doing it wrong...
				// ; sfv created by SFV Checker
				// "01-the_contingency_plan-apocalypse_in_stereo-kzt.mp3" 9757ce72
				// ; Total 5 File(s) Combined CRC32 Checksum: a4b5d005
				if (substr($fileName, 0, 1) === '"' && substr($fileName, strlen($fileName) - 1) === '"') {
					$fileName = substr($fileName, 1, strlen($fileName) - 2);
				}
				// make CRC value always 8 characters
				// missing leading zeros with '; SFV created with ioA by WarC' in sfv
				// Die_Aerzte_-_Das_Besten_Von_Kurz_Nach_Frueher_Bis_Jetzt-2CD-DE-1994-SDR_INT
				$crc = rtrim(substr($line, $spaceIndex + 1, 8));
				$result['files'][$fileName] = str_pad($crc, 8, '0', STR_PAD_LEFT);
			}
		}
	}

	// print_r($result);
	return $result;
}

/**
 * Converts MS DOS timestamp to something usable.
 * DosDateTimeToFileTime()
 * http://msdn.microsoft.com/en-us/library/ms724247(v=vs.85).aspx
 * @returns date in unix time
 */
function parseDosTime($dword) {
	$second = ($dword & 0x1F) * 2;
	$dword = $dword >> 5;
	$minute = $dword & 0x3F;
	$dword = $dword >> 6;
	$hour = $dword & 0x1F;
	$dword = $dword >> 5;
	$day = $dword & 0x1F;
	$dword = $dword >> 5;
	$month = $dword & 0x0F;
	$dword = $dword >> 4;
	$year = ($dword & 0x7F) + 1980;
	return mktime($hour, $minute, $second, $month, $day, $year);
}


/**
 * A RAR or SRR block used for reading all header fields.
 */
class Block {
	/**
	 * The constructor initializes a RAR block by reading the the basic 7 byte
	 * header fields. It checks if there is content after the block header.
	 * @param	open file handle
	 */
	public function __construct($fileHandle, &$warnings) {
		$this->fh = $fileHandle;
		$this->startOffset = ftell($fileHandle); // current location in the file
		$this->warnings = &$warnings;

		// reading basic 7 byte header block
		$array = unpack('vheaderCrc/CblockType/vflags/vheaderSize', fread($this->fh, 7));
		$this->headerCrc = $array['headerCrc'];
		$this->blockType = $array['blockType'];
		$this->flags = $array['flags'];
		$this->hsize = $array['headerSize'];
		$this->addSize = 0; // size of data after the header

		// check if block contains additional data
		$addSizeFlag = $array['flags'] & 0x8000;
		if ($addSizeFlag || ($array['blockType'] === 0x74) || ($array['blockType'] === 0x7A)) {
			// The BiA guys need some bitch slapping first:
			// they don't set the 0x8000 flag for RAR file blocks.
			if (!$addSizeFlag) {
				array_push($warnings, 'LONG_BLOCK flag (0x8000) not set for RAR File block (0x74).');
			}
			$array = unpack('VaddSize', fread($this->fh, 4));
			$this->addSize = $array['addSize'];
		}

		// only used to calculate the full size of a rar file
		// size header + size stored content
		// this content is removed for RAR blocks
		$this->fullSize = $this->hsize + $this->addSize;

		// -- check CRC of block header --
		$offset = ftell($this->fh);
		fseek($this->fh, $this->startOffset + 2, SEEK_SET);
		$crcData = fread($this->fh, $this->hsize - 2);
		// only the 4 lower order bytes are used
		$crc = crc32($crcData) & 0xffff;
		// igonore blocks with no CRC set (same as twice the blockType)
		if ($crc !== $this->headerCrc && $this->headerCrc !== 0x6969 // SRR Header
				&& $this->headerCrc !== 0x6a6a // SRR Stored File
				&& $this->headerCrc !== 0x6b6b // SRR OSO Hash
				&& $this->headerCrc !== 0x6c6c // SRR RAR Padding
				&& $this->headerCrc !== 0x7171 // SRR RAR block
				&& $this->blockType !== 0x72 // RAR marker block (fixed: magic number)
		) {
			// it can not fail here for releases such as Haven.S02E05.HDTV.XviD-P0W4
			global $BLOCKNAME;
			array_push($warnings, 'ERROR: Invalid block header CRC found: header is corrupt. (' .
					$BLOCKNAME[$this->blockType] . ', ' . $offset . ')');
		}
		// set offset back to where we started from
		fseek($this->fh, $offset, SEEK_SET);
	}

	/**
	 * Reads the additional fields for a SRR Header Block.
	 * @return	string	Name of the application used to create the SRR file.
	 */
	function readSrrAppName() {
		if ((dechex($this->flags) & 1)) {
			// read 2 fields after basic header block
			$length = unpack('vnamelength', fread($this->fh, 2));
			return fread($this->fh, $length['namelength']);
		} else {
			return ""; // there isn't an application name stored
		}
	}

	/**
	 * Reads the additional fields for a SRR Stored File Block.
	 */
	function srrReadStoredFileHeader() {
		$array = unpack('vlength', fread($this->fh, 2));
		if ($array['length'] !== 0) {
			$this->fileName = fread($this->fh, $array['length']);
		} else {
			array_push($this->warnings, 'Stored file with no name detected!');
			$this->fileName = "";
		}
		$this->storedFileStartOffset = ftell($this->fh);

		// skip possible (future) fields to start file
		$this->skipHeader();
	}
	
	/**
	 * Reads the OpenSubtitles.org Hashes.
	 */
	function srrOsoHashFileHeader() {
		// FILE SIZE
		$file_size = unpack('Vlow/Vhigh', fread($this->fh, 8));
		// add the high order bits before the low order bits and convert to decimal
		$lowhex = str_pad(dechex($file_size['low']), 8, '0', STR_PAD_LEFT);
		$highhex = dechex($file_size['high']);
		$this->fileSize = hexdec($highhex . $lowhex);
		
		// OSO HASH
		$oso_hash = unpack('Vlow/Vhigh', fread($this->fh, 8));
		// add the high order bits before the low order bits and convert to decimal
		$lowhex = str_pad(dechex($oso_hash['low']), 8, '0', STR_PAD_LEFT);
		$highhex = dechex($oso_hash['high']);
		$this->osoHash = $highhex . $lowhex;
		
		// FILE NAME
		$array = unpack('vnameSize', fread($this->fh, 2));
		$this->fileName = fread($this->fh, $array['nameSize']);
		
		// skip possible (future) fields
		fseek($this->fh, $this->startOffset, SEEK_SET);
		$this->data = fread($this->fh, $this->hsize);
	}

	/**
	 * Reads the additional fields for a SRR block that indicates
	 * that RAR blocks are following.
	 */
	function srrReadRarFileHeader() {
		$length = unpack('vlength', fread($this->fh, 2));
		if ($length['length'] !== 0) {
			$this->rarName = fread($this->fh, $length['length']);
		} else {
			array_push($this->warnings, 'RAR file with no name detected!');
			$this->rarName = "";
		}
	}

	/**
	 * Reads the additional fields for a RAR file block.
	 */
	function rarReadPackedFileHeader() {
		$array = unpack('Vus/Cos/VfileCrc/VfileTime/CunpackVersion/Cmethod/vnameSize/Vattr',
				fread($this->fh, 21));
		// $this->packSize = $this->addSize
		// $this->unpackedSize = $array['us'];
		$this->fileSize = $array['us'];
		$this->fileCrc = dechex($array['fileCrc']);
		// $this->os = $array['os'];
		$this->fileTime = parseDosTime($array['fileTime']);
		// $this->unpackVersion = $array['unpackVersion'];
		$this->compressionMethod = $array['method'];
		// $this->fileAttributes = $array['attr'];

		if ($this->flags & 0x100) {
			$high = unpack('VhighPackSize/VhighUnpackSize', fread($this->fh, 8));
			// $this->highPackSize = $high['highPackSize'];
			// $this->highUnpackSize = $high['highUnpackSize'];
			// add the high order bits before the low order bits and convert to decimal
			$lowhex = str_pad(dechex($array['us']), 8, '0', STR_PAD_LEFT);
			$highhex = dechex($high['highUnpackSize']);
			$this->fileSize = hexdec($highhex . $lowhex);
		}

		// Filename: unicode
		if ($this->flags & 0x200) {
			// Split the standard filename and unicode data from the file_name field
			$fn = explode("\x00", fread($this->fh, $array['nameSize']));
		
			// Decompress the unicode filename, encode the result as UTF-8
			$uc = new RarUnicodeFilename($fn[0], $fn[1]);
			if ($ucname = $uc->decode()) {
				$this->fileName = @iconv('UTF-16LE', 'UTF-8//IGNORE//TRANSLIT', $ucname);
		
			// Fallback to the standard filename
			} else {
				$this->fileName = $fn[0];
			}
		
		// Filename: non-unicode
		} else {
			$this->fileName = fread($this->fh, $array['nameSize']);
		}

		// salt and extra time fields are here and not interesting
		$this->skipHeader();
	}

	/**
	 * Set the file handle cursor at the end of the header.
	 * Data that follows can be a next block or a stored file.
	 */
	function skipHeader() {
		// skip whole header of the block
		fseek($this->fh, $this->startOffset + $this->hsize, SEEK_SET);
	}

	/**
	 * Sets file cursor to the next block based on the values in the header!
	 */
	function skipBlock() {
		fseek($this->fh, $this->startOffset + $this->hsize + $this->addSize, SEEK_SET);
	}
}

function detectFileFormat($fileHandle) {
	$ft = FileType::Unknown;
	$firstBytes = strtoupper(bin2hex(fread($fileHandle, 4))); // first 4 bytes

	switch($firstBytes) {
		case '1A45DFA3':
			$ft = FileType::MKV;
			break;
		case '52494646': // RIFF
			$ft = FileType::AVI;
			break;
		case '3026B275':
			$ft = FileType::WMV;
			break;
		case '664C6143': // fLaC
			$ft = FileType::FLAC;
			break;
		case '53525346': // SRSF
			$ft = FileType::MP3;
			break;
		case '5354524D': // STRM
			$ft = FileType::STREAM;
			break;
		default:
			if ('66747970' ===	bin2hex(fread($fileHandle, 4))) { // next 4 bytes
				$ft = FileType::MP4;
			} elseif (substr($firstBytes, 0, 6) == '494433') { // ID3
				// can be MP3 or FLAC
				fseek($fileHandle, 6, SEEK_SET);
				$tagLen = calcDecTagLen(fread($fileHandle, 4));
				if (fseek($fileHandle, 10 + $tagLen, SEEK_SET) === 0) {
					if (fread($fileHandle, 4) === 'fLaC') {
						$ft = FileType::FLAC;
					} else {
						$ft = FileType::MP3;
					}
				} else {
					// not enough data in the file
					$ft = FileType::MP3;
				}
			}
	}
	rewind($fileHandle);
	return $ft;
}

// Calculate Tag Length from bytes 6-10 of ID3 header
// function from comment: http://php.net/manual/en/ref.id3.php
function calcDecTagLen($word) {
	$m = 1;
	$int = 0;
	for ($i=strlen($word)-1;$i>-1;$i--) {
		$int +=$m*ord($word[$i]);
		$m=$m*128;
	}
	return $int;
}

function parse_srs_avi($fh, $srsSize) {
	$result = array();
	$result['trackData'] = array();

	$rr = new RiffReader($fh, $srsSize);
	$done = FALSE;
	while (!$done && $rr->read()) {
		if ($rr->chunkType == 'LIST') {
			$rr->moveToChild();
		} else {
			if ($rr->fourcc == 'SRSF') {
				$data = $rr->readContents();
				$result['fileData'] = new FileData($data);
			} elseif ($rr->fourcc == 'SRST') {
				$data = $rr->readContents();
				$track = new TrackData($data);
				$result['trackData'][$track->trackNumber] = $track;
			} elseif ($rr->chunkType == 'MOVI') {
				$done = TRUE;
				break;
			} else {
				$rr->skipContents();
			}
		}
	}
	return $result;
}

class EbmlType {
	const Segment = 'segment';
	const ReSample = 'resample';
	const ReSampleFile = 'resamplefile';
	const ReSampleTrack = 'resampletrack';
	const Cluster = 'cluster';
	const AttachmentList = 'attachmentlist';
	const Block = 'block';
	const Unknown = 'whatever';
}

function parse_srs_mkv($fh, $srsSize) {
	$result = array();
	$result['trackData'] = array();

	$er = new EbmlReader($fh, $srsSize);
	$done = FALSE;
	while(!$done && $er->read()) {
		if ($er->etype == EbmlType::Segment || $er->etype == EbmlType::ReSample) {
			$er->moveToChild();
		} elseif ($er->etype == EbmlType::ReSampleFile) {
			$data = $er->readContents();
			$result['fileData'] = new FileData($data);
		} elseif ($er->etype == EbmlType::ReSampleTrack) {
			$data = $er->readContents();
			$track = new TrackData($data);
			$result['trackData'][$track->trackNumber] = $track;
		} elseif ($er->etype == EbmlType::Cluster || $er->etype == EbmlType::AttachmentList) {
			$er->skipContents();
			$done = TRUE;
		} else {
			$er->skipContents();
		}
	}
	return $result;
}

function parse_srs_mp4($fh, $srsSize) {
	$result = array();
	$result['trackData'] = array();

	$mr = new MovReader($fh, $srsSize);
	while($mr->read()) {
		if ($mr->atomType == 'SRSF') {
			$data = $mr->readContents();
			$result['fileData'] = new FileData($data);
		} elseif ($mr->atomType == 'SRST') {
			$data = $mr->readContents();
			$track = new TrackData($data);
			$result['trackData'][$track->trackNumber] = $track;
		} elseif ($mr->atomType == 'mdat') {
			$mr->moveToChild();
		} else {
			$mr->skipContents();
		}
	}
	return $result;
}

function parse_srs_wmv($fh, $srsSize) {
	$result = array();
	$result['trackData'] = array();
	
	$GUID_SRS_FILE = 'SRSFSRSFSRSFSRSF';
	$GUID_SRS_TRACK = 'SRSTSRSTSRSTSRST';
	$GUID_SRS_PADDING = "PADDINGBYTESDATA";
	
	$start_pos = strpos(fread($fh, $srsSize), $GUID_SRS_FILE);
	fseek($fh, $start_pos);

	while ($start_pos < $srsSize) {
	   $guid = fread($fh, 16);
	   $object_size = unpack('Vlow/Vhigh', fread($fh, 8));
	   // add the high order bits before the low order bits and convert to decimal
	   $lowhex = str_pad(dechex($object_size['low']), 8, '0', STR_PAD_LEFT);
	   $highhex = dechex($object_size['high']);
	   $size = hexdec($highhex . $lowhex);
	   
	   $object_data = fread($fh, $size - 24);
	   if ($guid == $GUID_SRS_FILE) {
		   $result['fileData'] = new FileData($object_data);
	   } elseif ($guid == $GUID_SRS_TRACK) {
		   $track = new TrackData($object_data);
		   $result['trackData'][$track->trackNumber] = $track;
	   } elseif ($guid == $GUID_SRS_PADDING) {
		   // normally the padding bytes are always zero, but not in this sample
		   // all the padding bytes are stored in this block when at least one of them isn't zero
		   $result['paddingSize'] = $size - 24;
	   } else {
		   // all interesting objects are grouped together
		   // nothing interesting follows
		   break;
	   }
		
	   $start_pos = ftell($fh);
	}

	return $result;
}

function parse_srs_flac($fh, $srsSize) {
	$result = array();
	$result['trackData'] = array();
	
	$fr = new FlacReader($fh, $srsSize);
	while($fr->read()) {
		if ($fr->blockType == 's') {
			$data = $fr->readContents();
			$result['fileData'] = new FileData($data);
		} elseif ($fr->blockType == 't') {
			$data = $fr->readContents();
			$track = new TrackData($data);
			$result['trackData'][$track->trackNumber] = $track;
		} elseif ($fr->blockType == 'u') {
			$result['trackData'][1] = read_fingerprint_data($result['trackData'][1], $fr->readContents());
		} else {
			$fr->skipContents();
		}
		
		// mandatory STREAMINFO metadata block encountered
		if ($fr->blockType == "\0") {
			break; // stop parsing FLAC file
		}
	}
	
	return $result;
}

function read_fingerprint_data($track, $data) {
	$lengths = unpack('Vduration/Vfplength', substr($data, 0, 8));
	$track->duration = $lengths['duration'];
	$track->fingerprint = substr($data, 8, $lengths['fplength']);
	return $track;
}

function parse_srs_mp3($fh, $srsSize) {
	$result = array();
	$result['trackData'] = array();
	
	$data = fread($fh, $srsSize);
	
	// won't work correctly if there is the string 'SRSF' in the ID3 tag
	// => so skip the ID3 tag
	if (substr($data, 0, 3) === 'ID3') {
		$tagLen = calcDecTagLen(substr($data, 6, 4));
		// srrDB Issue #70 (angelmoon)-hes_all_i_want_cd_pg2k-bmi
		if($tagLen > $srsSize) {
			$next = strpos($data, 'ID3', 10);
			if($next < $srsSize) {
				$tagLen = calcDecTagLen(substr($data, $next + 6, 4));
				$data = substr($data, $next + 10 + $tagLen);
			}
		} else {
			$data = substr($data, 10 + $tagLen);
		}
	}
	
	$f = strpos($data, 'SRSF'); // file
	$t = strpos($data, 'SRST', $f); // track
	$p = strpos($data, 'SRSP', $t); // fingerprint
	if ($f !== FALSE) {
		$l = unpack('Vlength', substr($data, $f + 4, 4));
		$result['fileData'] = new FileData(substr($data, $f + 8, $l['length'] - 8));
	}
	if ($t !== FALSE) {
		$l = unpack('Vlength', substr($data, $t + 4, 4));
		$track = new TrackData(substr($data, $t + 8, $l['length'] - 8));
		$result['trackData'][$track->trackNumber] = $track;
	}
	if ($p !== FALSE) {
		$l = unpack('Vlength', substr($data, $p + 4, 4));
		$result['trackData'][1] = read_fingerprint_data($result['trackData'][1], substr($data, $p + 8, $l['length'] - 8));
	}
	
	return $result;
}	

function parse_srs_stream($fh, $srsSize) {
	$result = array();
	$result['trackData'] = array();
	
	$startPos = 0;
	fseek($fh, $startPos);
	
	while ($startPos < $srsSize) {
		if ($startPos + 8 > $srsSize) {
			break; // SRS file too small
		}

		// read header
		$marker = fread($fh, 4);
		$blockSize = unpack('Vsize', fread($fh, 4))['size'];

		if ($marker === 'SRSF') {
			$result['fileData'] = new FileData(fread($fh, $blockSize - 8));
		} elseif ($marker === 'SRST') {
			$track = new TrackData(fread($fh, $blockSize - 8));
			$result['trackData'][$track->trackNumber] = $track;
		}
	
		$startPos += $blockSize;
	}
	
	return $result;
}

class FileData {
	public function __construct($data) {
		$u = unpack('vflags/vappLength', substr($data, 0, 4));
		$this->flags = $u['flags'];
		$this->appName = substr($data, 4, $u['appLength']);
		$v = unpack('vnameLength', substr($data, 4 + $u['appLength'], 2));
		$this->name = substr($data, 4 + $u['appLength'] + 2, $v['nameLength']);
		$offset = 4 + $u['appLength'] + 2 + $v['nameLength'];

		$w = unpack('Vlow/Vhigh/Vcrc32', substr($data, $offset, 12));
		// add the high order bits before the low order bits and convert to decimal
		$lowhex = str_pad(dechex($w['low']), 8, '0', STR_PAD_LEFT);
		$highhex = dechex($w['high']);
		$this->fileSize = hexdec($highhex . $lowhex);
		$this->crc32 = str_pad(dechex($w['crc32']), 8, '0', STR_PAD_LEFT);
	}
}

class TrackData {
	public function __construct($data) {
		$u = unpack('vflags', substr($data, 0, 2));
		$this->flags = $u['flags'];

		// MP4 files can have larger track numbers
		if ($this->flags & 0x8) { // big track number
			// 4 bytes
			$u = unpack('VtrackNumber', substr($data, 2, 6));
			$extra = 2;
		} else {
			// 2 bytes
			$u = unpack('vtrackNumber', substr($data, 2, 4));
			$extra = 0;
		}
		$this->trackNumber = $u['trackNumber'];

		if ($this->flags & 0x4) { // big file
			$w = unpack('Vlow/Vhigh', substr($data, 4 + $extra, 8));
			$lowhex = str_pad(dechex($w['low']), 8, '0', STR_PAD_LEFT);
			$highhex = dechex($w['high']);
			$this->dataSize = hexdec($highhex . $lowhex);
			$add = 8;
		} else {
			$w = unpack('Vsize', substr($data, 4 + $extra, 4));
			$this->dataSize = $w['size'];
			$add = 4;
		}
		$w = unpack('Vlow/Vhigh/vsignaturesize', substr($data, 4 + $extra + $add, 10));
		$lowhex = str_pad(dechex($w['low']), 8, '0', STR_PAD_LEFT);
		$highhex = dechex($w['high']);
		// location where the track is located in the main file (often zero)
		$this->matchOffset = hexdec($highhex . $lowhex);
		$this->signatureSize = $w['signaturesize'];
		// signature bytes we don't need
	}
}

class RiffReader {
	public function __construct($fileHandle, $srsSize) {
		$this->fh = $fileHandle;
		$this->fileSize = $srsSize;
		$this->readDone = TRUE;

		$this->chunkType = null;
		$this->hasPadding = FALSE;
		$this->chunkLength = 0;
		$this->fourcc = '';
	}

	public function read() {
		$chunkStartPosition = ftell($this->fh);
		$this->readDone = FALSE;

		if ($chunkStartPosition + 8 > $this->fileSize) {
			return FALSE;
		}

		$header = fread($this->fh, 8);
		$this->fourcc = substr($header, 0, 4);
		$this->chunkLength = unpack('Vlength', substr($header, 4, 4));
		$this->chunkLength = $this->chunkLength['length'];

		if ($this->fourcc == 'RIFF' || $this->fourcc == 'LIST') {
			fseek($this->fh, 4, SEEK_CUR);
			//echo $this->chunkLength . "\n";
			$this->chunkLength -= 4;
			$this->chunkType = 'LIST';
		} else {
			if (ctype_digit(substr($header, 0, 2))) {
				$this->chunkType = 'MOVI';
			} else {
				$this->chunkType = '	';
			}
		}
		$this->hasPadding = $this->chunkLength % 2 == 1;

		return TRUE;
	}

	public function readContents() {
		if ($this->readDone) {
			fseek($this->fh, -$this->chunkLength - $this->hasPadding, SEEK_CUR);
		}

		$this->readDone = TRUE;
		$buffer = null;

		if ($this->chunkType != 'MOVI') {
			$buffer = fread($this->fh, $this->chunkLength);
		}

		if ($this->hasPadding) {
			fseek($this->fh, 1, SEEK_CUR);
		}
		return $buffer;
	}

	public function skipContents() {
		if (!$this->readDone) {
			$this->readDone = TRUE;

			if ($this->chunkType != 'MOVI') {
				fseek($this->fh, $this->chunkLength, SEEK_CUR);
			}

			if ($this->hasPadding) {
				fseek($this->fh, 1, SEEK_CUR);
			}
		}
	}

	public function moveToChild() {
		$this->readDone = TRUE;
	}
}

class EbmlReader {
	public function __construct($fileHandle, $srsSize) {
		$this->fh = $fileHandle;
		$this->fileSize = $srsSize;
		$this->readDone = TRUE;

		$this->etype = null;
		$this->elementLength = 0;
	}

	private function String2Hex($string){
		$hex='';
		for ($i=0; $i < strlen($string); $i++){
			$hex .= str_pad(dechex(ord($string[$i])), 2,  '0', STR_PAD_LEFT);
		}
		return $hex;
	}

	public function read() {
		assert($this->readDone == TRUE || $this->etype == EbmlType::Block);
		// too little data
		if (ftell($this->fh) + 2 > $this->fileSize) {
			return FALSE;
		}

		$this->readDone = FALSE;

		// element ID
		$readByte = ord(fread($this->fh, 1));
		$idLengthDescriptor = $this->getUIntLength($readByte);
		$elementHeader = str_pad(dechex($readByte), 2,	'0', STR_PAD_LEFT);
		if ($idLengthDescriptor > 1) {
			$elementHeader .= $this->String2Hex(fread($this->fh, $idLengthDescriptor - 1));
		}

		// data size
		$readByte = ord(fread($this->fh, 1));
		$dataLengthDescriptor = $this->getUIntLength($readByte);
		$elementHeader .= str_pad(dechex($readByte), 2,  '0', STR_PAD_LEFT);
		if ($dataLengthDescriptor > 1) {
			$elementHeader .= $this->String2Hex(fread($this->fh, $dataLengthDescriptor - 1));
		}

		assert ($idLengthDescriptor + $dataLengthDescriptor == strlen($elementHeader)/2);
		if ($idLengthDescriptor + $dataLengthDescriptor != strlen($elementHeader)/2)
			exit();

		// data
		$eh = strtoupper(substr($elementHeader, 0, 2*$idLengthDescriptor));
		switch ($eh) {
			case 'A1':
			case 'A2':
				$this->etype = EbmlType::Block;
				break;
			case '1F43B675':
				$this->etype = EbmlType::Cluster;
				break;
			case '18538067':
				$this->etype = EbmlType::Segment;
				break;
			case '1941A469':
				$this->etype = EbmlType::AttachmentList;
				break;
			case '1F697576':
				$this->etype = EbmlType::ReSample;
				break;
			case '6A75':
				$this->etype = EbmlType::ReSampleFile;
				break;
			case '6B75':
				$this->etype = EbmlType::ReSampleTrack;
				break;
			default:
				$this->etype = EbmlType::Unknown;
		}

		$this->elementLength = $this->getEbmlUInt($elementHeader, $idLengthDescriptor, $dataLengthDescriptor);

		return TRUE;
	}

	private function getUIntLength($lengthDescriptor) {
		$length = 0;
		for ($i=0;$i<8;$i++) {
			if (($lengthDescriptor & (0x80 >> $i)) != 0) {
				$length = $i + 1;
				break;
			}
		}
		return $length;
	}

	private function getEbmlUInt($buff, $offset, $count) {
		$size = hexdec(substr($buff, $offset*2, 2)) & (0xFF >> $count);
		for ($i=1;$i<$count;$i++) {
			$size = ($size << 8) + hexdec(substr($buff, $offset*2+$i*2, 2));
		}
		return $size;
	}

	public function readContents() {
		if ($this->readDone) {
			fseek($this->fh, -$this->elementLength, SEEK_CUR);
		}

		$this->readDone = TRUE;
		$buffer = null;

		// skip over removed ebml elements
		if ($this->etype != EbmlType::Block) {
			$buffer = fread($this->fh, $this->elementLength);
		}
		return $buffer;
	}

	public function skipContents() {
		if (!$this->readDone) {
			$this->readDone = TRUE;

			if ($this->etype != EbmlType::Block) {
				fseek($this->fh, $this->elementLength, SEEK_CUR);
			}
		}
	}

	public function moveToChild() {
		$this->readDone = TRUE;
	}
}

class MovReader {
	public function __construct($fileHandle, $srsSize) {
		$this->fh = $fileHandle;
		$this->fileSize = $srsSize;
		$this->readDone = TRUE;
		$this->atomType = '';
		$this->atomLength = 0;
		$this->atomStartPosition = 0;
		assert(ftell($this->fh) === 0);
	}

	public function read() {
		assert($this->readDone);
		$atomStartPosition = ftell($this->fh);
		$this->readDone = FALSE;

		if ($atomStartPosition + 8 > $this->fileSize) {
			return FALSE;
		}
		$header = fread($this->fh, 8);
		$u = unpack('Nlength', substr($header, 0, 4));
		$atomLength = $u['length'];
		$this->atomType = substr($header, 4, 4);

		// special sizes
		$hsize = 8;
		if ($atomLength === 1) {
			// 8-byte size field after the atom type
			$bsize = fread($this->fh, 8);
			$w = unpack('Nhigh/Nlow', substr($bsize, 0, 8));
			// add the high order bits before the low order bits and convert to decimal
			$lowhex = str_pad(dechex($w['low']), 8, '0', STR_PAD_LEFT);
			$highhex = dechex($w['high']);
			$atomLength = hexdec($highhex . $lowhex);
			$hsize += 8;
		} elseif ($atomLength === 0) {
			// FoV/COMPULSiON samples have an atom that consists of just 8 null bytes.
			// This is the case if it is followed by an mdat
			if ($this->atomType === "\x00\x00\x00\x00") {
				$atomLength = 8;
			} else {
				// the atom extends to the end of the file
				$atomLength = $this->fileSize - $atomStartPosition;
			}
		}
		$this->headerSize = $hsize;
		$this->atomLength = $atomLength;
		$this->atomStartPosition = $atomStartPosition;
			
		fseek($this->fh, $atomStartPosition, SEEK_SET);
		
		return TRUE;
	}

	public function readContents() {
		if ($this->readDone) {
			fseek($this->fh, $this->atomStartPosition, SEEK_SET);
		}

		$this->readDone = TRUE;
		$buffer = null;

		fseek($this->fh, $this->headerSize, SEEK_CUR);
		
		if ($this->atomType != 'mdat') {
			$buffer = fread($this->fh, $this->atomLength - $this->headerSize);
		}

		return $buffer;
	}

	public function skipContents() {
		if (!$this->readDone) {
			$this->readDone = TRUE;

			if ($this->atomType != 'mdat') {
				fseek($this->fh, $this->atomLength, SEEK_CUR);
			} else {
				fseek($this->fh, $this->headerSize, SEEK_CUR);
			}
		}
	}

	public function moveToChild() {
		$this->readDone = TRUE;
		fseek($this->fh, $this->headerSize, SEEK_CUR);
	}
}

class FlacReader {
	public function __construct($fileHandle, $srsSize) {
		$this->fh = $fileHandle;
		$this->fileSize = $srsSize;
		$this->readDone = TRUE;
		$this->blockType = '';
		$this->blockLength = 0;
		$this->blockStartPosition = 0;
		assert(ftell($this->fh) === 0);
	}

	public function read() {
		assert($this->readDone);
		
		$this->blockStartPosition = ftell($this->fh);
		$this->readDone = FALSE;
		
		if ($this->blockStartPosition == $this->fileSize) {
			return FALSE;
		}
		
		$header = fread($this->fh, 4);
		if ($header == 'fLaC') {
			$this->blockType = 'fLaC';
			$this->blockLength = 0;
			return TRUE;
		}
		if (substr($header, 0, 3) == 'ID3') {
			// set position to ID3 size field
			fseek($this->fh, $this->blockStartPosition + 6, SEEK_SET);
			$tagLen = calcDecTagLen(fread($this->fh, 4));
			$this->blockType = 'ID3';
			$this->blockLength = 10 + $tagLen - 4; // -4 to fit the flac model
			fseek($this->fh, $this->blockStartPosition + 4, SEEK_SET); // go back to the size of one METADATA_BLOCK_HEADER
			return TRUE;
		}

		/*
		# METADATA_BLOCK_HEADER
		# <1>	 Last-metadata-block flag: '1' if this block is the last
		#		 metadata block before the audio blocks, '0' otherwise.
		# <7>	 BLOCK_TYPE
		# <24>	 Length (in bytes) of metadata to follow
		#		 (does not include the size of the METADATA_BLOCK_HEADER)
		*/
		$this->blockType = substr($header, 0, 1);
		$size = unpack('Nsize', "\0" . substr($header, 1, 3));
		$this->blockLength = $size['size'];

		return TRUE;
	}

	public function readContents() {
		assert(!$this->readDone);
		$this->readDone = TRUE;
		return fread($this->fh, $this->blockLength);
	}

	public function skipContents() {
		if (!$this->readDone) {
			$this->readDone = TRUE;

			fseek($this->fh, $this->blockLength, SEEK_CUR);
		}
	}
}

/**
 * RarUnicodeFilename class.
 *
 * This utility class handles the unicode filename decompression for RAR files. It is
 * adapted directly from Marko Kreen's python script rarfile.py.
 *
 * @link https://github.com/markokr/rarfile
 *
 * @version 1.2
 */
class RarUnicodeFilename
{
	/**
	 * Initializes the class instance.
	 *
	 * @param	string	$stdName  the standard filename
	 * @param	string	$encData  the unicode data
	 * @return	void
	 */
	public function __construct($stdName, $encData)
	{
		$this->stdName = $stdName;
		$this->encData = $encData;
	}

	/**
	 * Decompresses the unicode filename by combining the standard filename with
	 * the additional unicode data, return value is encoded as UTF-16LE.
	 *
	 * @return	mixed  the unicode filename, or false on failure
	 */
	public function decode()
	{
		$highByte = $this->encByte();
		$encDataLen = strlen($this->encData);
		$flagBits = 0;

		while ($this->encPos < $encDataLen) {
			if ($flagBits == 0) {
				$flags = $this->encByte();
				$flagBits = 8;
			}
			$flagBits -= 2;

			switch (($flags >> $flagBits) & 3) {
				case 0:
					$this->put($this->encByte(), 0);
					break;
				case 1:
					$this->put($this->encByte(), $highByte);
					break;
				case 2:
					$this->put($this->encByte(), $this->encByte());
					break;
				default:
					$n = $this->encByte();
					if ($n & 0x80) {
						$c = $this->encByte();
						for ($i = 0; $i < (($n & 0x7f) + 2); $i++) {
							$lowByte = ($this->stdByte() + $c) & 0xFF;
							$this->put($lowByte, $highByte);
						}
					} else {
						for ($i = 0; $i < ($n + 2); $i++) {
							$this->put($this->stdByte(), 0);
						}
					}
			}
		}

		// Return the unicode string
		if ($this->failed) {return false;}
		return $this->output;
	}

	/**
	 * The standard filename data.
	 * @var string
	 */
	protected $stdName;

	/**
	 * The unicode data used for processing.
	 * @var string
	 */
	protected $encData;

	/**
	 * Pointer for the standard filename data.
	 * @var integer
	 */
	protected $pos = 0;

	/**
	 * Pointer for the unicode data.
	 * @var integer
	 */
	protected $encPos = 0;

	/**
	 * Did the decompression fail?
	 * @var boolean
	 */
	protected $failed = false;

	/**
	 * Decompressed unicode filename string.
	 * @var string
	 */
	protected $output;

	/**
	 * Gets the current byte value from the unicode data and increments the
	 * pointer if successful.
	 *
	 * @return	integer  encoded byte value, or 0 on fail
	 */
	protected function encByte()
	{
		if (isset($this->encData[$this->encPos])) {
			$ret = ord($this->encData[$this->encPos]);
		} else {
			$this->failed = true;
			$ret = 0;
		}
		$this->encPos++;
		return $ret;
	}

	/**
	 * Gets the current byte value from the standard filename data.
	 *
	 * @return	integer  standard byte value, or placeholder on fail
	 */
	protected function stdByte()
	{
		if (isset($this->stdName[$this->pos])) {
			return ord($this->stdName[$this->pos]);
		}
		$this->failed = true;
		return ord('?');
	}

	/**
	 * Builds the output for the unicode filename string in 16-bit blocks (UTF-16LE).
	 *
	 * @param	integer  $low	low byte value
	 * @param	integer  $high	high byte value
	 * @return	void
	 */
	protected function put($low, $high)
	{
		$this->output .= chr($low);
		$this->output .= chr($high);
		$this->pos++;
	}

} // End RarUnicodeFilename class

/* ----- end of rescene.php ----- */
