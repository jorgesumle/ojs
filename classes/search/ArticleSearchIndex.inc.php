<?php

/**
 * @file classes/search/ArticleSearchIndex.inc.php
 *
 * Copyright (c) 2003-2012 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class ArticleSearchIndex
 * @ingroup search
 *
 * @brief Class to add content to the article search index.
 */

import('lib.pkp.classes.search.SearchFileParser');
import('lib.pkp.classes.search.SearchHTMLParser');
import('lib.pkp.classes.search.SearchHelperParser');

define('SEARCH_STOPWORDS_FILE', 'lib/pkp/registry/stopwords.txt');

// Words are truncated to at most this length
define('SEARCH_KEYWORD_MAX_LENGTH', 40);

class ArticleSearchIndex {

	/**
	 * Index a block of text for an object.
	 * @param $objectId int
	 * @param $text string
	 * @param $position int
	 */
	function _indexObjectKeywords($objectId, $text, &$position) {
		$searchDao =& DAORegistry::getDAO('ArticleSearchDAO');
		$keywords =& $this->filterKeywords($text);
		for ($i = 0, $count = count($keywords); $i < $count; $i++) {
			if ($searchDao->insertObjectKeyword($objectId, $keywords[$i], $position) !== null) {
				$position += 1;
			}
		}
	}

	/**
	 * Add a block of text to the search index.
	 * @param $articleId int
	 * @param $type int
	 * @param $text string
	 * @param $assocId int optional
	 */
	function _updateTextIndex($articleId, $type, $text, $assocId = null) {
		$searchDao =& DAORegistry::getDAO('ArticleSearchDAO');
		$objectId = $searchDao->insertObject($articleId, $type, $assocId);
		$position = 0;
		$this->_indexObjectKeywords($objectId, $text, $position);
	}

	/**
	 * Add a file to the search index.
	 * @param $articleId int
	 * @param $type int
	 * @param $fileId int
	 */
	function updateFileIndex($articleId, $type, $fileId) {
		// Check whether a search plug-in jumps in.
		$hookResult =& HookRegistry::call(
			'ArticleSearchIndex::updateFileIndex',
			array($articleId, $type, $fileId)
		);

		// If no search plug-in is activated then fall back to the
		// default database search implementation.
		if ($hookResult === false || is_null($hookResult)) {
			import('classes.file.ArticleFileManager');
			$fileManager = new ArticleFileManager($articleId);
			$file =& $fileManager->getFile($fileId);

			if (isset($file)) {
				$parser =& SearchFileParser::fromFile($file);
			}

			if (isset($parser)) {
				if ($parser->open()) {
					$searchDao =& DAORegistry::getDAO('ArticleSearchDAO');
					$objectId = $searchDao->insertObject($articleId, $type, $fileId);

					$position = 0;
					while(($text = $parser->read()) !== false) {
						$this->_indexObjectKeywords($objectId, $text, $position);
					}
					$parser->close();
				}
			}
		}
	}

	/**
	 * Delete keywords from the search index.
	 * @param $articleId int
	 * @param $type int optional
	 * @param $assocId int optional
	 */
	function deleteTextIndex($articleId, $type = null, $assocId = null) {
		// Check whether a search plug-in jumps in.
		$hookResult =& HookRegistry::call(
			'ArticleSearchIndex::deleteTextIndex',
			array($articleId, $type, $assocId)
		);

		// If no search plug-in is activated then fall back to the
		// default database search implementation.
		if ($hookResult === false || is_null($hookResult)) {
			$searchDao =& DAORegistry::getDAO('ArticleSearchDAO'); /* @var $searchDao ArticleSearchDAO */
			return $searchDao->deleteArticleKeywords($articleId, $type, $assocId);
		}
	}

	/**
	 * Split a string into a clean array of keywords
	 * @param $text string
	 * @param $allowWildcards boolean
	 * @return array of keywords
	 */
	function &filterKeywords($text, $allowWildcards = false) {
		$minLength = Config::getVar('search', 'min_word_length');
		$stopwords =& $this->_loadStopwords();

		// Join multiple lines into a single string
		if (is_array($text)) $text = join("\n", $text);

		$cleanText = Core::cleanVar($text);

		// Remove punctuation
		$cleanText = String::regexp_replace('/[!"\#\$%\'\(\)\.\?@\[\]\^`\{\}~]/', '', $cleanText);
		$cleanText = String::regexp_replace('/[\+,:;&\/<=>\|\\\]/', ' ', $cleanText);
		$cleanText = String::regexp_replace('/[\*]/', $allowWildcards ? '%' : ' ', $cleanText);
		$cleanText = String::strtolower($cleanText);

		// Split into words
		$words = String::regexp_split('/\s+/', $cleanText);

		// FIXME Do not perform further filtering for some fields, e.g., author names?

		// Remove stopwords
		$keywords = array();
		foreach ($words as $k) {
			if (!isset($stopwords[$k]) && String::strlen($k) >= $minLength && !is_numeric($k)) {
				$keywords[] = String::substr($k, 0, SEARCH_KEYWORD_MAX_LENGTH);
			}
		}
		return $keywords;
	}

	/**
	 * Return list of stopwords.
	 * FIXME Should this be locale-specific?
	 * @return array with stopwords as keys
	 */
	function &_loadStopwords() {
		static $searchStopwords;

		if (!isset($searchStopwords)) {
			// Load stopwords only once per request (FIXME Cache?)
			$searchStopwords = array_count_values(array_filter(file(SEARCH_STOPWORDS_FILE), create_function('&$a', 'return ($a = trim($a)) && !empty($a) && $a[0] != \'#\';')));
			$searchStopwords[''] = 1;
		}

		return $searchStopwords;
	}

	/**
	 * Index article metadata.
	 * @param $article Article
	 */
	function indexArticleMetadata(&$article) {
		// Check whether a search plug-in jumps in.
		$hookResult =& HookRegistry::call(
			'ArticleSearchIndex::indexArticleMetadata',
			array($article)
		);

		// If no search plug-in is activated then fall back to the
		// default database search implementation.
		if ($hookResult === false || is_null($hookResult)) {
			// Build author keywords
			$authorText = array();
			$authors = $article->getAuthors();
			for ($i=0, $count=count($authors); $i < $count; $i++) {
				$author =& $authors[$i];
				array_push($authorText, $author->getFirstName());
				array_push($authorText, $author->getMiddleName());
				array_push($authorText, $author->getLastName());
				$affiliations = $author->getAffiliation(null);
				if (is_array($affiliations)) foreach ($affiliations as $affiliation) { // Localized
					array_push($authorText, $affiliation);
				}
				$bios = $author->getBiography(null);
				if (is_array($bios)) foreach ($bios as $bio) { // Localized
					array_push($authorText, strip_tags($bio));
				}
			}

			// Update search index
			$articleId = $article->getId();
			$this->_updateTextIndex($articleId, ARTICLE_SEARCH_AUTHOR, $authorText);
			$this->_updateTextIndex($articleId, ARTICLE_SEARCH_TITLE, $article->getTitle(null));
			$this->_updateTextIndex($articleId, ARTICLE_SEARCH_ABSTRACT, $article->getAbstract(null));

			$this->_updateTextIndex($articleId, ARTICLE_SEARCH_DISCIPLINE, (array) $article->getDiscipline(null));
			$this->_updateTextIndex($articleId, ARTICLE_SEARCH_SUBJECT, array_merge(array_values((array) $article->getSubjectClass(null)), array_values((array) $article->getSubject(null))));
			$this->_updateTextIndex($articleId, ARTICLE_SEARCH_TYPE, $article->getType(null));
			$this->_updateTextIndex($articleId, ARTICLE_SEARCH_COVERAGE, array_merge(array_values((array) $article->getCoverageGeo(null)), array_values((array) $article->getCoverageChron(null)), array_values((array) $article->getCoverageSample(null))));
			// FIXME Index sponsors too?
		}
	}

	/**
	 * Index supp file metadata.
	 * @param $suppFile object
	 */
	function indexSuppFileMetadata(&$suppFile) {
		// Check whether a search plug-in jumps in.
		$hookResult =& HookRegistry::call(
			'ArticleSearchIndex::indexSuppFileMetadata',
			array($suppFile)
		);

		// If no search plug-in is activated then fall back to the
		// default database search implementation.
		if ($hookResult === false || is_null($hookResult)) {
			// Update search index
			$articleId = $suppFile->getArticleId();
			$this->_updateTextIndex(
				$articleId,
				ARTICLE_SEARCH_SUPPLEMENTARY_FILE,
				array_merge(
					array_values((array) $suppFile->getTitle(null)),
					array_values((array) $suppFile->getCreator(null)),
					array_values((array) $suppFile->getSubject(null)),
					array_values((array) $suppFile->getTypeOther(null)),
					array_values((array) $suppFile->getDescription(null)),
					array_values((array) $suppFile->getSource(null))
				),
				$suppFile->getFileId()
			);
		}
	}

	/**
	 * Index all article files (supplementary and galley).
	 * @param $article Article
	 */
	function indexArticleFiles(&$article) {
		// Check whether a search plug-in jumps in.
		$hookResult =& HookRegistry::call(
			'ArticleSearchIndex::indexArticleFiles',
			array($article)
		);

		// If no search plug-in is activated then fall back to the
		// default database search implementation.
		if ($hookResult === false || is_null($hookResult)) {
			// Index supplementary files
			$fileDao =& DAORegistry::getDAO('SuppFileDAO');
			$files =& $fileDao->getSuppFilesByArticle($article->getId());
			foreach ($files as $file) {
				if ($file->getFileId()) {
					$this->updateFileIndex($article->getId(), ARTICLE_SEARCH_SUPPLEMENTARY_FILE, $file->getFileId());
				}
				$this->indexSuppFileMetadata($file);
			}
			unset($files);

			// Index galley files
			$fileDao =& DAORegistry::getDAO('ArticleGalleyDAO');
			$files =& $fileDao->getGalleysByArticle($article->getId());
			foreach ($files as $file) {
				if ($file->getFileId()) {
					$this->updateFileIndex($article->getId(), ARTICLE_SEARCH_GALLEY_FILE, $file->getFileId());
				}
			}
		}
	}

	/**
	 * Rebuild the search index for one or all journals.
	 * @param $log boolean Whether to display status information
	 *  to stdout.
	 * @param $journal Journal If given the user wishes to
	 *  re-index only one journal. Not all search implementations
	 *  may be able to do so. Most notably: The default SQL
	 *  implementation does not support journal-specific re-indexing
	 *  as index data is not partitioned by journal.
	 */
	function rebuildIndex($log = false, $journal = null) {
		// Check whether a search plug-in jumps in.
		$hookResult =& HookRegistry::call(
			'ArticleSearchIndex::rebuildIndex',
			array($log, $journal)
		);

		// If no search plug-in is activated then fall back to the
		// default database search implementation.
		if ($hookResult === false || is_null($hookResult)) {
			// Check that no journal was given as we do
			// not support journal-specific re-indexing.
			if (is_a($journal, 'Journal')) die(__('search.cli.rebuildIndex.indexingByJournalNotSupported') . "\n");

			// Clear index
			if ($log) echo __('search.cli.rebuildIndex.clearingIndex') . ' ... ';
			$searchDao =& DAORegistry::getDAO('ArticleSearchDAO');
			$searchDao->clearIndex();
			if ($log) echo __('search.cli.rebuildIndex.done') . "\n";

			// Build index
			$journalDao =& DAORegistry::getDAO('JournalDAO');
			$articleDao =& DAORegistry::getDAO('ArticleDAO');

			$journals =& $journalDao->getJournals();
			while (!$journals->eof()) {
				$journal =& $journals->next();
				$numIndexed = 0;

				if ($log) echo __('search.cli.rebuildIndex.indexing', array('journalName' => $journal->getLocalizedTitle())) . ' ... ';

				$articles =& $articleDao->getArticlesByJournalId($journal->getId());
				while (!$articles->eof()) {
					$article =& $articles->next();
					if ($article->getDateSubmitted()) {
						$this->indexArticleMetadata($article);
						$this->indexArticleFiles($article);
						$numIndexed++;
					}
					unset($article);
				}

				if ($log) echo __('search.cli.rebuildIndex.result', array('numIndexed' => $numIndexed)) . "\n";
				unset($journal);
			}
		}
	}
}

?>
