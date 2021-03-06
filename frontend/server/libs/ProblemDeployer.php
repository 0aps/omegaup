<?php

/**
 * Unzip and deploy a problem
 *
 * @author joemmanuel
 */

/**
 * Types of problems
 */
class ProblemType {	
	const InputOutput = 1;
	const Interactive = 2;
	const CustomValidator = 3;
}


class ProblemDeployer {
	
	const MAX_ZIP_FILESIZE = 209715200; //200 * 1024 * 1024;

	public $filesToUnzip;
	private $imageHashes;
	private $casesFiles;
	private $hasValidator = false;
	private $problemDirPath;
	private $problemType = ProblemType::InputOutput; 
	private $log;

	public function __construct() {
		$this->log = Logger::getLogger("ProblemDeployer");
	}

	/**
	 * 
	 * @param array $zipFilesArray
	 * @param ZipArchive $zip
	 * @return boolean
	 */
	private function checkProblemStatements(array $zipFilesArray, ZipArchive $zip) {
		$this->log->info("Checking problem statements...");

		// We need at least one statement
		$statements = preg_grep('/^statements\/[a-zA-Z]{2}\.markdown$/', $zipFilesArray);

		if (count($statements) < 1) {
			throw new InvalidParameterException("No statements found");
		}

		// Add statements to the files to be unzipped
		foreach ($statements as $file) {
			// Revisar que los statements no esten vacíos                    
			if (strlen($zip->getFromName($file, 1)) < 1) {
				throw new InvalidParameterException("Statement {$file} is empty.");
			}

			$this->log->info("Adding statements to the files to be unzipped: " . $file);
			$this->filesToUnzip[] = $file;
		}

		// Also extract any images in the statements directory.
		$images = preg_grep('/^statements\/.*\.(gif|jpg|jpeg|png)$/', $zipFilesArray);

		// Add images to the files to be unzipped.
		foreach ($images as $file) {
			$this->filesToUnzip[] = $file;
			$this->imageHashes[substr($file, strlen('statements/'))] = true;
		}

		return true;
	}
	
	/**
	 * Validates the cases of a problem zip without testplan
	 * 
	 * @param ZipArchive $zip
	 * @param array $zipFilesArray
	 * @return boolean
	 * @throws InvalidParameterException
	 */
	private function checkCases(ZipArchive $zip, array $zipFilesArray) {
			
		$this->log->info("Validating /cases");
		
		// Necesitamos tener al menos 1 caso
		$cases = 0;
		$inCasesCount = 0;
		$outCasesCount = 0;

		// Add all files in cases/ that end either in .in or .out
		for ($i = 0; $i < count($zipFilesArray); $i++) {
			$path = $zipFilesArray[$i];

			if (strpos($path, "cases/") == 0) {
				// If we find a .in case
				if ($this->endsWith($path, ".in", true)) {					
					$inCasesCount++;
					
					// Look for the .out pair
					$outPath = substr($path, 0, strlen($path) - 3) . ".out";
					$idx = $zip->locateName($outPath, ZipArchive::FL_NOCASE);

					if ($idx !== FALSE) {
						$cases++;
						$this->filesToUnzip[] = $path;
						$this->casesFiles[] = $path;
						$this->filesToUnzip[] = $zipFilesArray[$idx];
						$this->casesFiles[] = $zipFilesArray[$idx];
					} else {
						throw new InvalidParameterException(".out for case \"$path\" not found.");
					}
				} else if ($this->endsWith($path, ".out", true)) {
					$outCasesCount++;
				}
			}
		}

		if ($cases === 0) {
			throw new InvalidParameterException("No cases found.");
		}
		
		if ($this->problemType == ProblemType::InputOutput || $this->problemType == ProblemType::Interactive )
		{
			// Check that each .out 
			if ($inCasesCount !== $outCasesCount) {
				throw new InvalidParameterException("Equal number of .in and .out files expected. .in found: $inCasesCount, .out found: $outCasesCount");
			}
		}

		$this->log->info($cases . " cases found.");

		return true;
	}
	
	/**
	 * Validates problem zip given that a problem zip containts a testplan file 
	 * 
	 * @param ZipArchive $zip
	 * @param array $zipFilesArray
	 * @return boolean
	 * @throws InvalidParameterException
	 */
	private function checkCasesWithTestplan(ZipArchive $zip, array $zipFilesArray) {

		// Get testplan contents into an array
		$testplan = $zip->getFromName("testplan");
		$testplan_array = array();

		// LOL RegEx magic to get test case names from testplan
		preg_match_all('/^\\s*([^#]+?)\\s+(\\d+)\\s*$/m', $testplan, $testplan_array);

		for ($i = 0; $i < count($testplan_array[1]); $i++) {
			// Check .in file
			$path = 'cases' . DIRECTORY_SEPARATOR . $testplan_array[1][$i] . '.in';
			if ($zip->getFromName($path) === FALSE) {
				throw new InvalidParameterException("Not able to find " . $testplan_array[1][$i] . " input in testplan.");
			}

			$this->filesToUnzip[] = $path;
			$this->casesFiles[] = $path;

			// Check .out file
			$path = 'cases' . DIRECTORY_SEPARATOR . $testplan_array[1][$i] . '.out';
			if ($zip->getFromName($path) === FALSE) {
				throw new InvalidParameterException("Not able to find " . $testplan_array[1][$i] . " output in testplan.");
			}

			$this->filesToUnzip[] = $path;
			$this->casesFiles[] = $path;
		}

		return true;
	}

	/**
	 * Entry point for zip validation
	 * Determines the type of problem we are deploying
	 * 
	 * @return boolean
	 * @throws InvalidParameterException
	 */
	private function validateZip() {

		$this->log->info("Validating zip...");

		if (!array_key_exists("problem_contents", $_FILES)) {
			$this->log->error("\$_FILES global does not contain problem_contents.");
			throw new InvalidParameterException("problem_contents is invalid.");
		}

		if (isset($_FILES['problem_contents']) &&
				!FileHandler::GetFileUploader()->IsUploadedFile($_FILES['problem_contents']['tmp_name'])) {
			$this->log->error("GetFileUploader()->IsUploadedFile() check failed for \$_FILES['problem_contents']['tmp_name'].");
			throw new InvalidParameterException("problem_contents is invalid.");
		}

		$this->filesToUnzip = array();
		$this->imageHashes = array();
		$this->casesFiles = array();

		$originalZip = $_FILES['problem_contents']['tmp_name'];

		$this->log->info("Opening $originalZip...");
		$zip = new ZipArchive();
		$resource = $zip->open($originalZip);

		$size = 0;
		if ($resource === TRUE) {
			// Get list of files
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$this->log->info("Found inside zip: '" . $zip->getNameIndex($i) . "'");
				$zipFilesArray[] = $zip->getNameIndex($i);

				// Sum up the size
				$statI = $zip->statIndex($i);
				$size += $statI['size'];

				// If the file is THE validator for custom outputs...
				if (stripos($zip->getNameIndex($i), 'validator.') === 0) {
					$this->hasValidator = true;
					$this->filesToUnzip[] = $zip->getNameIndex($i);
					
					$this->problemType = ProblemType::CustomValidator;					
					$this->log->info("Validator found: " . $zip->getNameIndex($i));
				}

				// Interactive problems.
				if (stripos($zip->getNameIndex($i), 'interactive/') === 0) {
					$this->filesToUnzip[] = $zip->getNameIndex($i);
					
					$this->problemType = ProblemType::Interactive;
					$this->log->info("Interactive folder found: " . $zip->getNameIndex($i));
				}
			}

			if ($size > ProblemDeployer::MAX_ZIP_FILESIZE) {
				throw new InvalidParameterException("Extracted zip size ($size) over max allowed MB. Rejecting.");
			}

			try {

				// Look for testplan
				if (in_array("testplan", $zipFilesArray)) {

					$returnValue = $this->checkCasesWithTestplan($zip, $zipFilesArray);
					$this->log->info("testplan found, checkCasesWithTestPlan=" . $returnValue);
					$this->filesToUnzip[] = 'testplan';
				} else {
					$this->log->info("testplan not found");
					$this->checkCases($zip, $zipFilesArray);
				}

				// Log files to unzip
				$this->log->info("Files to unzip: ");
				foreach ($this->filesToUnzip as $file) {
					$this->log->info($file);
				}

				// Look for statements
				$returnValue = $this->checkProblemStatements($zipFilesArray, $zip);
				$this->log->info("checkProblemStatements=" . $returnValue . ".");
			} catch (Exception $e) {

				// Close zip
				$this->log->error("Validation Failed. Closing zip");
				$zip->close();

				throw $e;
			}			
			
			// Close zip
			$this->log->info("closing zip");
			$zip->close();

			return $returnValue;
		} else {
			throw new InvalidParameterException("Unable to open zip." . ZipHandler::zipFileErrMsg($resource));
		}

		return;
	}

	
	/**
	 * Read already deployed statements from filesystem and apply transformations
	 * $lang.markdown => statements/$lang.html as well as encoding checks
	 * 
	 * @param string $dirpath
	 * @param array $filesToUnzip
	 */
	private function handleStatements($dirpath, array $filesToUnzip = null) {

		// Get a list of all available statements.
		// At this point, zip is validated and it has at least 1 statement. No need to check
		$statements = preg_grep('/^statements\/[a-zA-Z]{2}\.markdown$/', $filesToUnzip);
		$this->log->info("Handling statements...");

		// Transform statements from markdown to HTML  
		foreach ($statements as $statement) {

			// Get the path to the markdown unzipped file
			$markdown_filepath = $dirpath . DIRECTORY_SEPARATOR . $statement;
			$this->log->info("Reading file " . $markdown_filepath);

			// Read the contents of the original markdown file
			$markdown_file_contents = FileHandler::ReadFile($markdown_filepath);

			// Deploy statement raw (.markdown) and transformed (.html)
			$this->HTMLizeStatement($dirpath, $statement, $markdown_file_contents);
		}
	}
	
	/**
	 * Given the $lang.markdown contents, deploys the .markdown file and creates the .html file
	 * 
	 * @param string $problemBasePath
	 * @param string $statementFileName
	 * @param string $markdown_file_contents
	 */
	private function HTMLizeStatement($problemBasePath, $statementFileName, $markdown_file_contents) {
		$this->log->info("HTMLizing statement: " . $statementFileName);
		
		// Path used to deploy the raw problem statement (.markdown)
		$markdown_filepath = $problemBasePath . DIRECTORY_SEPARATOR . $statementFileName;
		
		// Fix for Windows Latin-1 statements:
		// For now, assume that if it is not UTF-8, then it is Windows Latin-1 and then convert
		if (!mb_check_encoding($markdown_file_contents, "UTF-8")) {
			$this->log->info("File is not UTF-8.");

			// Convert from ISO-8859-1 (Windows Latin1) to UTF-8
			$this->log->info("Converting encoding from ISO-8859-1 to UTF-8 (Windows Latin1 to UTF-8, fixing accents)");
			$markdown_file_contents = mb_convert_encoding($markdown_file_contents, "UTF-8", "ISO-8859-1");

			// Then overwrite it into the statement file
			$this->log->info("Overwriting file after encoding conversion: " . $markdown_filepath);
			FileHandler::CreateFile($markdown_filepath, $markdown_file_contents);
		} else {
			$this->log->info("File is UTF-8. Nice :)");
		}

		// Transform markdown to HTML
		$this->log->info("Transforming markdown to html");
		$html_file_contents = Markdown($markdown_file_contents, array($this, 'imageMarkdownCallback'));

		// Get the language of this statement            
		$lang = basename($statementFileName, ".markdown");

		$html_filepath = $problemBasePath . DIRECTORY_SEPARATOR . "statements" . DIRECTORY_SEPARATOR . $lang . ".html";

		// Save the HTML file in the path .../problem_alias/statements/lang.html            
		$this->log->info("Saving HTML statement in " . $html_filepath);
		FileHandler::CreateFile($html_filepath, $html_file_contents);
	}

	
	/**
	 * Deploys the given image when present in the statement contents
	 * 
	 * @param type $imagepath
	 * @return type
	 */
	public function imageMarkdownCallback($imagepath) {
		if (array_key_exists($imagepath, $this->imageHashes)) {
			if (is_bool($this->imageHashes[$imagepath])) {
				
				// copy the image to somewhere in IMAGES_PATH, get its SHA-1 sum,
				// and store it in the imageHashes array.				
				
				$source = $this->problemDirPath . "/statements/" . $imagepath;
				$hash = sha1_file($source);
				$extension = substr($imagepath, strpos($imagepath, "."));
				$hashedFilename =  "$hash$extension";
				$copyDestination = IMAGES_PATH . $hashedFilename;
				
				$this->log->info("Deploying image: copying $source to $copyDestination");
				
				FileHandler::Copy($source, $copyDestination);				
				$this->imageHashes[$imagepath] = IMAGES_URL_PATH . $hashedFilename;
			}
			return $this->imageHashes[$imagepath];
		} else {
			// Also support absolute urls.
			return $imagepath;
		}
	}

	/**
	 * Handle unzipped cases
	 * 
	 * @param string $dirpath
	 * @param array $casesFiles
	 * @throws InvalidFilesystemOperationException
	 */
	private function handleCases($dirpath, array $casesFiles) {

		$this->log->info("Handling cases...");

		// Aplying normalizr to cases
		$return_var = 0;
		$output = array();
		$normalizr_cmd = BIN_PATH . "/normalizr " . $dirpath . DIRECTORY_SEPARATOR . "cases/* 2>&1";
		$this->log->info("Applying normalizr: " . $normalizr_cmd);
		exec($normalizr_cmd, $output, $return_var);

		// Log errors
		if ($return_var !== 0) {
			$this->log->warn("normalizr failed with error: " . $return_var);
		} else {
			$this->log->info("normalizr succeeded");
		}
		$this->log->info(implode("\n", $output));

		// After normalizrfication, we need to generate a zip file that will be
		// passed between grader and runners with the INPUT files...                
		// Create path to cases.zip and proper cmds
		$cases_zip_path = $dirpath . DIRECTORY_SEPARATOR . 'cases.zip';
		$cases_to_be_zipped = $dirpath . DIRECTORY_SEPARATOR . "cases/*.in";

		// cmd to be executed in console
		$zip_cmd = "zip -j " . $cases_zip_path . " " . $cases_to_be_zipped . " 2>&1";

		// Execute zip command
		$output = array();
		$this->log->info("Zipping input cases using: " . $zip_cmd);
		exec($zip_cmd, $output, $return_var);

		// Check zip cmd return value
		if ($return_var !== 0) {
			// D:
			$this->log->error("zipping cases failed with error: " . $return_var);
			throw new InvalidFilesystemOperationException("Error creating cases.zip. Please check log for details");
		} else {
			// :D
			$this->log->info("zipping cases succeeded:");
			$this->log->info(implode("\n", $output));
		}

		// Generate sha1sum for cases.zip distribution from grader to runners
		$this->log->info("Writing to : " . $dirpath . DIRECTORY_SEPARATOR . "inputname");
		file_put_contents($dirpath . DIRECTORY_SEPARATOR . "inputname", sha1_file($cases_zip_path));
	}

	/**
	 * 
	 * @param string $dirpath
	 * @param string $path_to_contents_zip
	 * @return type
	 */
	private function updateContentsDotZip($dirpath, $path_to_contents_zip) {

		// Delete whathever the user sent us
		if (!unlink($path_to_contents_zip)) {
			$this->log->warn("Unable to delete contents.zip to replace with original contents!: " . $path_to_contents_zip);
			return;
		}

		// Set directory to the one where contents.zip is to handle paths inside
		// the zip correcly 
		$original_dir = getcwd();
		chdir($dirpath);

		// cmd to be executed in console
		// cases/*
		$output = array();

		$zip_cmd = "zip -r " . $path_to_contents_zip . " cases/* 2>&1";
		$this->log->info("Zipping contents.zip cases using: " . $zip_cmd);
		exec($zip_cmd, $output, $return_var);

		// Check zip cmd return value
		if ($return_var !== 0) {
			// D:
			$this->log->error("zipping cases/* contents.zip failed with error: " . $return_var);
		} else {
			// :D
			$this->log->info("zipping cases contents.zip succeeded:");
			$this->log->info(implode("\n", $output));
		}

		// 
		// statements/*
		$output = array();

		$zip_cmd = "zip -r " . $path_to_contents_zip . " statements/* 2>&1";
		$this->log->info("Zipping contents.zip statements using: " . $zip_cmd);
		exec($zip_cmd, $output, $return_var);


		// Check zip cmd return value
		if ($return_var !== 0) {
			// D:
			$this->log->error("zipping statements/* contents.zip failed with error: " . $return_var);
		} else {
			// :D
			$this->log->info("zipping statements contents.zip succeeded:");
			$this->log->info(implode("\n", $output));
		}

		// get back to original dir
		chdir($original_dir);
	}

	/**
	 * Returns the path where the problem contents will be placed
	 * 
	 * @param Request $r
	 * @return string
	 */
	private function getDirpath(Request $r) {
		return PROBLEMS_PATH . DIRECTORY_SEPARATOR . $r["alias"];
	}

	/**
	 * 
	 * @param string $dirpath
	 * @return string
	 */
	private function getFilepath($dirpath) {
		return $dirpath . DIRECTORY_SEPARATOR . 'contents.zip';
	}

	/**
	 * Removes a problem from the filesystem
	 * 
	 * @param string $dirpath
	 */
	public function deleteProblemFromFilesystem(Request $r) {
		// Drop contents into path required
		FileHandler::DeleteDirRecursive($this->getDirpath($r));
	}
	
	/**
	 * Helper function to check whether a string ends with $needle
	 * 
	 * @param string $haystack
	 * @param string $needle
	 * @param boolean $case
	 * @return boolean
	 */
	private function endsWith($haystack, $needle, $case) {
		$expectedPosition = strlen($haystack) - strlen($needle);

		$ans = false;

		if ($case) {
			return strrpos($haystack, $needle, 0) === $expectedPosition;
		} else {
			return strripos($haystack, $needle, 0) === $expectedPosition;
		}
	}

	/**
	 * Updates an statement.
	 * Assumes $r["lang"] and $r["statement"] are set
	 * $r["alias"] should contain the problem alias
	 * 
	 * @param Request $r
	 * @throws ProblemDeploymentFailedException
	 */
	public function updateStatement(Request $r) {
		
		try {
			$this->log->info("Starting statement update, lang: " . $r["lang"]);
			
			// Delete statement files
			$markdownFile = $this->getDirpath($r) . DIRECTORY_SEPARATOR . "statements" . DIRECTORY_SEPARATOR . $r["lang"] . ".markdown";
			$htmlFile = $this->getDirpath($r) . DIRECTORY_SEPARATOR . "statements" . DIRECTORY_SEPARATOR . $r["lang"] . ".html";
			FileHandler::DeleteFile($markdownFile);
			FileHandler::DeleteFile($htmlFile);
			
			// Deploy statement
			FileHandler::CreateFile($markdownFile, $r["statement"]);
			$this->HTMLizeStatement($this->getDirpath($r), $r["lang"] . ".markdown", $r["statement"]);
			
		} catch (Exception $e) {
			throw new ProblemDeploymentFailedException($e);
		}
	}
	
	/**
	 * Validates zip contents and updates the problem
	 * 
	 * @param Request $r
	 */
	public function update(Request $r) {
		$this->deploy($r, true);
	}		
	
	/**
	 * Validates zip contents and deploys the problem
	 * 
	 * @param Request $r
	 * @param type $isUpdate
	 * @throws InvalidFilesystemOperationException
	 */
	public function deploy(Request $r, $isUpdate = false) {
				
		try {			
			$this->validateZip();
			
			// Create paths			
			$dirpath = $this->getDirpath($r);
			$this->problemDirPath = $dirpath;
			$filepath = $this->getFilepath($dirpath);

			if ($isUpdate === true) {
				$this->deleteProblemFromFilesystem($r);
			}

			// Making target directory
			FileHandler::MakeDir($dirpath);

			// Move stuff uploaded by user from PHP realm to our directory
			FileHandler::MoveFileFromRequestTo('problem_contents', $filepath);

			// Unzip the user's zip
			ZipHandler::DeflateZip($filepath, $dirpath, $this->filesToUnzip);

			// Handle statements
			$this->handleStatements($dirpath, $this->filesToUnzip);

			// Handle cases
			$this->handleCases($dirpath, $this->casesFiles);

			// Update contents.zip
			$this->updateContentsDotZip($dirpath, $filepath);
		} catch (Exception $e) {
			throw new ProblemDeploymentFailedException($e);
		} 
	}

	/**
	 * Gets the maximum output file size. Returns -1 if there is a
	 * custom validator.
	 * 
	 * @param Request $r
	 * @throws InvalidFilesystemOperationException
	 */
	public function getOutputLimit(Request $r) {
		$dirpath = $this->getDirpath($r);
		$has_validator = false;

		if ($handle = opendir($dirpath)) {
			while (false !== ($entry = readdir($handle))) {
				if (stripos($entry, 'validator.') === 0) {
					$has_validator = true;
					break;
				}
			}
			closedir($handle);
		}

		if ($has_validator) {
			return -1;
		}

		$dirpath .= '/cases';

		$output_limit = 10240;

		if ($handle = opendir($dirpath)) {
			while (false !== ($entry = readdir($handle))) {
				if (!$this->endsWith($entry, '.out', true)) continue;

				$output_limit = max($output_limit, filesize("$dirpath/$entry"));
			}
			closedir($handle);
		}

		return (int)(($output_limit + 4095) / 4096 + 1) * 4096;
	}
}

