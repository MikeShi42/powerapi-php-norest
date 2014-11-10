<?php

/**
 * Copyright (c) 2013 Henri Watson
 *
 * Permission is hereby granted, free of charge, to any person obtaining
 * a copy of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included
 * in all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
 * OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
 * THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 *
 * @author		Henri Watson
 * @package		User
 * @version		2.3
 * @license		http://opensource.org/licenses/MIT	The MIT License
 */

namespace PowerAPI;

/** Handles post-authentication functions. (fetching transcripts, parsing data, etc.) */
class User {
	private $url, $version, $cookiePath, $ua, $homeContents, $courses;
	
	
	public function __construct(&$core, $homeContents) {
		$this->core = &$core;
		$this->homeContents = $homeContents;

		$this->courses = $this->_createCourses();
	}
	
	/**
	 * Pull the authenticated user's PESC HighSchoolTranscript from the server
	 * @return string PESC HighSchoolTranscript
	*/
	public function fetchTranscript() {
		$result = $this->core->_request('guardian/studentdata.xml?ac=download');
		
		return $result;
	}

	/**
	 * Parse the authenticated user's grades from the retrieved home page
	 * @return array
	*/
    private function _createCourses() {
        $result = $this->homeContents;

        //Create and Load Auth Page DOM
        $HomeDom = new \DOMDocument();
        $HomeDom->substituteEntities = false;
        $HomeDom->formatOutput = false;
        $HomeDom->resolveExternals = false;
        $HomeDom->recover = true;
	libxml_use_internal_errors(true);
        $HomeDom->loadHTML($result);
	libxml_clear_errors();
        $HomeXPath = new \DOMXPath($HomeDom);


        /* Parse different terms */

        //Determine if the terms are going to be using td (SM) or th (Arc) tags.
        $termTag = 'th';
        //Naive assumption that they will always use rowspan="2"
        if($HomeXPath->query('//td[@rowspan="2"]')->length > 0){
            $termTag='td';
        }

        $tableHeadElems = $HomeXPath->query('//'.$termTag.'[@rowspan="2"]');
        $startIndex = -1;

        //Find the start index of the term listing
        foreach($tableHeadElems as $key=>$item){
            //Assumption that Exp will always be first
            if(strcmp($item->nodeValue, "Exp") == 0){
                $startIndex = $key;
                break;
            }
        }

        //If terms are not found
        if($startIndex == -1){
            throw new \Exception('Unable to find terms.');
        }

        //Grab the terms
        $terms = [];
        for($i=$startIndex+2; $i<$tableHeadElems->length-$startIndex-2; $i++){
            $terms[] = $tableHeadElems->item($i)->nodeValue;
        }

        //Store the table that holds the classes and grades
        $tableElement = $HomeXPath->query('../..', $tableHeadElems->item(0))->item(0);

        /* Parse classes */

        //Find all table rows
        $tableRows = $HomeXPath->query('tr', $tableElement);
        foreach($tableRows as $index=>$node){
            //Get all elements in table row
            $classLinks = $HomeXPath->query('td/a[contains(@href, "scores.html")]', $node);
//            echo $classLinks->length;
            if($classLinks->length > 0){
                $classesA[] = new Course($this->core, $this->DOMinnerHTML($tableRows->item($index)), $terms);
            }
        }

        return $classesA;
    }

	/**
	 * Parse the school's name from the retrieved home page
	 * @return string school's name
	*/
	public function getSchoolName() {
		preg_match('/<div id="print-school">(.*?)<br>/s', $this->homeContents, $schoolName);
		
		return trim($schoolName[1]);
	}
	
	/**
	 * Parse the authenticated user's name from the retrieved home page
	 * @return string user's name
	*/
	public function getUserName() {
		preg_match('/<li id="userName" .*?<span>(.*?)<\/span>/s', $this->homeContents, $userName);
		
		return trim($userName[1]);
	}

	/**
	 * Return an array of courses
	 * @return array courses
	*/
	public function getCourses() {
		return $this->courses;
	}

	/*
	 * Pull the student assignment list from the server, timeframe set to 360
	 * @return string PPStudentAsmList
	 */
	public function fetchAssignmentList(){
		$result = $this->core->_request('guardian/ppstudentasmtlist.html?timeframe=360');
		return $result;
	}

	/**
	 * Parse the user's GPA
	 * @return double user's GPA
	 */
	public function getGPA() {
		preg_match('/<td align="center">.*?GPA.*?:(.*?)<\/td>/s', $this->homeContents, $gpa);
		$gpaVal = trim($gpa[1]);
		if(is_numeric($gpaVal))
			return $gpaVal;
		return false;
	}

    //Get innerHTMl of DOMNode
    function DOMinnerHTML($element)
    {
        $innerHTML = "";
        $children = $element->childNodes;
        foreach ($children as $child)
        {
            $tmp_dom = new \DOMDocument();
            $tmp_dom->appendChild($tmp_dom->importNode($child, true));
            $innerHTML.=trim($tmp_dom->saveHTML());
        }
        //Replace encoded ampersand with symbol.
        return str_replace('&amp;', '&', $innerHTML);
    }
}
