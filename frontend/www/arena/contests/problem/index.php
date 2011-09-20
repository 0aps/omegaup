<?php

/**
 * 
 * Please read full (and updated) documentation at: 
 * https://github.com/omegaup/omegaup/wiki/Arena 
 *
 *
 * 
 * GET /contests/
 * Lista (por default de los últimos 10 concursos) que el usuario "puede ver"
 *
 * */

define("WHOAMI", "API");
require_once("../../../../server/inc/bootstrap.php");
require_once("../../../../server/api/ShowProblemInContest.php");


$apiHandler = new ShowProblemInContest();
$apiHandler->ExecuteApi();
