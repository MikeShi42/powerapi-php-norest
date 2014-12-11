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
 * @package		Course
 * @version		2.3
 * @license		http://opensource.org/licenses/MIT	The MIT License
 */

namespace PowerAPI;

/** Handles post-authentication functions. (fetching transcripts, parsing data, etc.) */
class Course {
	private $core, $html; // Passed in variables
    private $name, $teacher, $scores, $period, $attendance, $roomNumber, $terms; // Scraped variables

    /*
    $scores:[
        $term:[
            'assignments':[
                $index: [
                    'due',
                    'category',
                    'assignment',
                    'score',
                    'percent',
                    'grade'
                ]
            ],
            'score'
        ]
    ]
    $categories:[
        $categoryName:[
            'weight',
            'drops'
        ]
    ]
     * */

    public function __construct(&$core, $html, $t) {
        $this->core = &$core;
        $this->html = $html;
        $this->terms = $t;

        $this->_populateCourse();
    }

	/**
	 * Parse an <A> tag
	 * @return array tag's title and destination URL
	*/
	private function _splitA($strip) {
		if (substr($strip, 0, 2) == '<a') {
			preg_match('/<a href="(.*?)">(.*?)<\/a>/s', $strip, $stripped);
			return Array(
				'title' => $stripped[2],
				'url' => $stripped[1]
			);
		} else {
			return Array('title' => $strip);
		}
	}

	/**
	 * Populate the object with the course's information
	 * @return void
	*/
    private function _populateCourse() {
        preg_match('/<td align="left">(?:\s*)(.*?)(?:&nbsp;)?(?:\s*)<br>(?:.*?)<a (?:href|onclick)="(javascript|mailto):(.*?)">(.*?)<\/a>(?:\s*)(?:&nbsp;)?(?:-&nbsp;Rm: )?(.*?)(?:\s*?)<\/td>/s', $this->html, $classData);
        $this->name = $classData[1];
        //If SM, regex will match differently
        if(strcmp($classData[2], 'javascript') === 0){
            $this->teacher = Array(
                'name' => $classData[5],
                'email' => null
            );
            $this->roomNumber = null;
        }elseif(strcmp($classData[2], 'mailto') === 0){
            $this->name = $classData[1];
            $this->teacher = Array(
                'name' => $classData[4],
                'email' => $classData[3]
            );
            $this->roomNumber = $classData[5];
        }

        preg_match_all('/<td>(.*?)<\/td>/s', $this->html, $databits, PREG_SET_ORDER);
        $this->period = $databits[0][1];

        $absences = $this->_splitA($databits[count($databits)-2][1]);
        if (!isset($absences['url'])) {
            $this->attendance['absences']['count'] = $absences['title'];
        } else {
            $this->attendance['absences'] = Array(
                'count' => $absences['title'],
                'url' => $absences['url']
            );
        }

        $tardies = $this->_splitA($databits[count($databits)-1][1]);
        if (!isset($tardies['url'])) {
            $this->attendance['tardies']['count'] = $tardies['title'];
        } else {
            $this->attendance['tardies'] = Array(
                'count' => $tardies['title'],
                'url' => $tardies['url']
            );
        }

        preg_match_all('/<a href="scores.html\?(.*?)">(.*?)<\/a>/s', $this->html, $scores, PREG_SET_ORDER);

	foreach ($scores as $score) {
		preg_match('/frn\=(.*?)\&fg\=(.*)/s', $score[1], $URLbits);
		$scoreT = explode('<br>', $score[2]);
		if ($score[2] !== '--' && !is_numeric($scoreT[0])) {	// This is here to handle special cases with schools using letter grades
			$this->scores[$URLbits[2]]['score'] = $scoreT[1];		//  or grades not being posted
			$this->scores[$URLbits[2]]['letter'] = $scoreT[0]; //also record letter grade
		} else if ($score[2] !== '--') {
			$this->scores[$URLbits[2]]['score'] = $scoreT[0];
			$this->scores[$URLbits[2]]['letter'] = '';
		}else
		{
			$this->scores[$URLbits[2]]['score'] = 0;
			$this->scores[$URLbits[2]]['letter'] = '-';
		}
		$this->scores[$URLbits[2]]['url'] = 'scores.html?'.$score[1];
	}
    }

	/**
	 * Fetch the information for a term and store it
	 * @return void
	*/
    private function _fetchTerm($term) {
        $result = $this->core->_request('guardian/'.$this->scores[$term]['url']);

        preg_match('/<table border="0" cellpadding="0" cellspacing="0" align="center" width="99%">(.*?)<\/table>/s', $result, $assignments);
	if(!array_key_exists(1, $assignments)){ \Illuminate\Support\Facades\Log::info("{$this->teacher['name']}'s {$this->name} misparsed in PowerAPI."); \Illuminate\Support\Facades\Log::info($result); }
        preg_match_all('/<tr bgcolor="(.*?)">(.*?)<\/tr>/s', $assignments[1], $assignments, PREG_SET_ORDER);
	$data = array(); //in case there are no assignments
        foreach ($assignments as $assignmentHTML) {
            preg_match_all('/<td(.*?)?>(.*?)<\/td>/s', $assignmentHTML[2], $assignmentData, PREG_SET_ORDER);
            $assignment['due'] = $assignmentData[0][2];
            $assignment['category'] = strip_tags($assignmentData[1][2]);
            $assignment['assignment'] = strip_tags($assignmentData[2][2]);


            if ($assignmentData[3][2] == "")
                $assignment['codes']['collected'] = false;
            else
                $assignment['codes']['collected'] = true;
            if ($assignmentData[4][2] == "")
                $assignment['codes']['late'] = false;
            else
                $assignment['codes']['late'] = true;
            if ($assignmentData[5][2] == "")
                $assignment['codes']['missing'] = false;
            else
                $assignment['codes']['missing'] = true;
            if ($assignmentData[6][2] == "")
                $assignment['codes']['exempt'] = false;
            else
                $assignment['codes']['exempt'] = true;
            if ($assignmentData[7][2] == "")
                $assignment['codes']['excluded'] = false;
            else
                $assignment['codes']['excluded'] = true;

            $assignment['score'] = strip_tags($assignmentData[8][2]);
            $assignment['percent'] = $assignmentData[9][2];
            $assignment['grade'] = $assignmentData[10][2];

            $data[] = $assignment;
        }
        $this->scores[$term]['assignments'] = $data;

        preg_match_all('/<div class="comment">.*?<pre>(.*?)<\/pre>.*?<\/div>/s', $result, $comments, PREG_SET_ORDER);
        $this->comments[$term]['teacher'] = $comments[0][1];
        $this->comments[$term]['section'] = $comments[1][1];

	$index = strpos($result, '<tr><th class="bold">Term ' . $term . '</th>'); //find the term (assuming this matches the right place -_-)
	$index = strpos($result, '<tr>', $index + 30); //find where the rows start
	$substr = substr($result, $index, strpos($result, '</table>', $index) - $index); //only look at the text inside this table
	preg_match_all( //match each row
		'#<tr>\s*<td>([^<]+)</td>\s*' . //capture first cell, which will either be "Category Based" or "Total Points"
		'<td[^>]+>([^<]*)</td>\s*' . '<td[^>]+>([^<]*)</td>\s*' . '<td[^>]+>([^<]*)</td>\s*' . '</tr>\s*#' //and capture next 3 cells
		, $substr, $weights, PREG_SET_ORDER);
	//TODO no information on dropped scores with "Total Points"
	if(!array_key_exists(0, $weights)) \Illuminate\Support\Facades\Log::info("{$this->teacher['name']}'s {$this->name} misparsed weights, transaction {$this->core->getTransactionID()}");
	if($weights[0][1] === 'Total Points')
	{
		//unweighted
		$this->categories[$term] = null;
	}else
	{
		foreach($weights as $rawCategory)
		{
			if($rawCategory[1] !== 'Category Based')
				continue; //this should never happen
			$category = array();
			$category['weight'] = $rawCategory[3];
			$category['drops'] = $rawCategory[4];
			$categoryName = html_entity_decode($rawCategory[2]);
			$this->categories[$term][$categoryName] = $category; //$this->categories['P2']['Homework'] = $category;
		}
	}
    }

	/**
	 * Return the course's name
	 * @return string course name
	*/
	public function getName() {
		return $this->name;
	}

	/**
	 * Return the course's scores in an array
	 * @return array course's scores
	*/
	public function getScores() {
		foreach ($this->scores as $term => $data) {
			$return[$term] = $data['score'];
		}
		return $return;
	}

	/**
	 * Return the term's comments in an array
	 * Returns false if the term doesn't exist.
	 * @param string term name
	 * @return array term's comments
	*/
	public function getComments($term) {
		$term = strtoupper($term); // normalise term name
		if (!isset($this->scores[$term]))
			return false;

		if (!isset($this->comments[$term])) {
			$this->_fetchTerm($term);
		}

		return $this->comments[$term];
	}

	/**
	 * Return the term's assignments in an array
	 * Returns false if the term doesn't exist.
	 * @param string term name
	 * @return array term's assignments
	*/
	public function getAssignments($term) {
		$term = strtoupper($term); // normalise term name
		if (!isset($this->scores[$term]))
			return false;

		if (!isset($this->scores[$term]['assignments'])) {
			$this->_fetchTerm($term);
		}

		return $this->scores[$term]['assignments'];
	}

	public function getTeacher() {
		return $this->teacher;
	}

	public function getPeriod() {
		return $this->period;
	}

	public function getRoomNumber() {
		return $this->roomNumber;
	}

	public function getLetters(){
		foreach ($this->scores as $term => $data) {
			$return[$term] = $data['letter'];
		}
		return $return;
	}

	public function getLatestTerm()
	{
		$terms = array_keys($this->scores);
		return $terms[count($terms) - 1];
	}

	//if this returns null, the class is unweighted
	public function getCategoryDetails($term)
	{
		//mostly copy-pasted from getAssignments
		$term = strtoupper($term);
		if (!isset($this->scores[$term]))
			return false;

		if (!isset($this->categories[$term])) {
			$this->_fetchTerm($term);
		}

		return $this->categories[$term];
	}
	
	
	public function getCore(){
		return $this->core;
	}
}
