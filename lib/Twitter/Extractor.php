<?php
/**
 * @author     Mike Cochrane <mikec@mikenz.geek.nz>
 * @author     Nick Pope <nick@nickpope.me.uk>
 * @copyright  Copyright © 2010, Mike Cochrane, Nick Pope
 * @license    http://www.apache.org/licenses/LICENSE-2.0  Apache License v2.0
 * @package    Twitter
 */

require_once 'Regex.php';

/**
 * Twitter Extractor Class
 *
 * Parses tweets and extracts URLs, usernames, username/list pairs and
 * hashtags.
 *
 * Originally written by {@link http://github.com/mikenz Mike Cochrane}, this
 * is based on code by {@link http://github.com/mzsanford Matt Sanford} and
 * heavily modified by {@link http://github.com/ngnpope Nick Pope}.
 *
 * @author     Mike Cochrane <mikec@mikenz.geek.nz>
 * @author     Nick Pope <nick@nickpope.me.uk>
 * @copyright  Copyright © 2010, Mike Cochrane, Nick Pope
 * @license    http://www.apache.org/licenses/LICENSE-2.0  Apache License v2.0
 * @package    Twitter
 */
class Twitter_Extractor extends Twitter_Regex {

  /**
   * @var boolean
   */
  protected $extractURLWithoutProtocol = true;

  /**
   * Provides fluent method chaining.
   *
   * @param  string  $tweet        The tweet to be converted.
   *
   * @see  __construct()
   *
   * @return  Twitter_Extractor
   */
  public static function create($tweet) {
    return new self($tweet);
  }

  /**
   * Reads in a tweet to be parsed and extracts elements from it.
   *
   * Extracts various parts of a tweet including URLs, usernames, hashtags...
   *
   * @param  string  $tweet  The tweet to extract.
   */
  public function __construct($tweet) {
    parent::__construct($tweet);
  }

  /**
   * Extracts all parts of a tweet and returns an associative array containing
   * the extracted elements.
   *
   * @return  array  The elements in the tweet.
   */
  public function extract() {
    return array(
      'hashtags' => $this->extractHashtags(),
      'urls'     => $this->extractURLs(),
      'mentions' => $this->extractMentionedUsernames(),
      'replyto'  => $this->extractRepliedUsernames(),
      'hashtags_with_indices' => $this->extractHashtagsWithIndices(),
      'urls_with_indices'     => $this->extractURLsWithIndices(),
      'mentions_with_indices' => $this->extractMentionedUsernamesWithIndices(),
    );
  }

  /**
   * Extract URLs, @mentions, lists and #hashtag from a given text/tweet.
   *
   * @return array list of extracted entities
   */
  public function extractEntitiesWithIndices() {
    $entities = array();
    $entities = array_merge($entities, $this->extractURLsWithIndices());
    $entities = array_merge($entities, $this->extractHashtagsWithIndices(false));
    $entities = array_merge($entities, $this->extractMentionedUsernamesOrListsWithIndices());
    $entities = array_merge($entities, $this->extractCashtagsWithIndices());
    $entities = $this->removeOverlappingEntities($entities);
    return $entities;
  }

  /**
   * Extracts all the hashtags from the tweet.
   *
   * @return  array  The hashtag elements in the tweet.
   */
  public function extractHashtags() {
    $hashtagsOnly = array();
    $hashtagsWithIndices = $this->extractHashtagsWithIndices();

    foreach ($hashtagsWithIndices as $hashtagWithIndex) {
      $hashtagsOnly[] = $hashtagWithIndex['hashtag'];
    }
    return $hashtagsOnly;
  }

  /**
   * Extracts all the cashtags from the tweet.
   *
   * @return  array  The cashtag elements in the tweet.
   */
  public function extractCashtags() {
    $cashtagsOnly = array();
    $cashtagsWithIndices = $this->extractCashtagsWithIndices();

    foreach ($cashtagsWithIndices as $cashtagWithIndex) {
      $cashtagsOnly[] = $cashtagWithIndex['cashtag'];
    }
    return $cashtagsOnly;
  }

  /**
   * Extracts all the URLs from the tweet.
   *
   * @return  array  The URL elements in the tweet.
   */
  public function extractURLs() {
    $urlsOnly = array();
    $urlsWithIndices = $this->extractURLsWithIndices();

    foreach ($urlsWithIndices as $urlWithIndex) {
      $urlsOnly[] = $urlWithIndex['url'];
    }
    return $urlsOnly;
  }

  /**
   * Extract all the usernames from the tweet.
   *
   * A mention is an occurrence of a username anywhere in a tweet.
   *
   * @return  array  The usernames elements in the tweet.
   */
  public function extractMentionedScreennames() {
    $usernamesOnly = array();
    $mentionsWithIndices = $this->extractMentionedUsernamesOrListsWithIndices();

    foreach ($mentionsWithIndices as $mentionWithIndex) {
      if (empty($mentionWithIndex['screen_name'])) {
        continue;
      }
      $usernamesOnly[] = $mentionWithIndex['screen_name'];
    }
    return $usernamesOnly;
  }

  /**
   * Extract all the usernames from the tweet.
   *
   * A mention is an occurrence of a username anywhere in a tweet.
   *
   * @return  array  The usernames elements in the tweet.
   * @deprecated since version 1.1.0
   */
  public function extractMentionedUsernames() {
    return $this->extractMentionedScreennames();
  }

  /**
   * Extract all the usernames replied to from the tweet.
   *
   * A reply is an occurrence of a username at the beginning of a tweet.
   *
   * @return  array  The usernames replied to in a tweet.
   */
  public function extractReplyScreenname() {
    $matched = preg_match(self::$patterns['valid_reply'], $this->tweet, $matches);
    # Check username ending in
    if ($matched && preg_match(self::$patterns['end_mention_match'], $matches[2])) {
      $matched = false;
    }
    return $matched ? $matches[1] : null;
  }
  /**
   * Extract all the usernames replied to from the tweet.
   *
   * A reply is an occurrence of a username at the beginning of a tweet.
   *
   * @return  array  The usernames replied to in a tweet.
   * @deprecated since version 1.1.0
   */
  public function extractRepliedUsernames() {
    return $this->extractReplyScreenname();
  }

  /**
   * Extracts all the hashtags and the indices they occur at from the tweet.
   *
   * @param boolean $checkUrlOverlap if true, check if extracted hashtags overlap URLs and remove overlapping ones
   * @return  array  The hashtag elements in the tweet.
   */
  public function extractHashtagsWithIndices($checkUrlOverlap = true) {
    if (!preg_match('/[#＃]/iu', $this->tweet)) {
      return array();
    }

    preg_match_all(self::$patterns['valid_hashtag'], $this->tweet, $matches, PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
    $tags = array();

    foreach ($matches as $match) {
      list($all, $before, $hash, $hashtag, $outer) = array_pad($match, 3, array('', 0));
      $start_position = $hash[1] > 0 ? mb_strlen(substr($this->tweet, 0, $hash[1])) : $hash[1];
      $end_position = $start_position + mb_strlen($hash[0] . $hashtag[0]);

      if (preg_match(self::$patterns['end_hashtag_match'], $outer[0])) {
        continue;
      }

      $tags[] = array(
          'hashtag' => $hashtag[0],
          'indices' => array($start_position, $end_position)
      );
    }

    if (!$checkUrlOverlap) {
      return $tags;
    }

    # check url overlap
    $urls = $this->extractURLsWithIndices();
    $entities = $this->removeOverlappingEntities(array_merge($tags, $urls));

    $validTags = array();
    foreach ($entities as $entity) {
      if (empty($entity['hashtag'])) {
        continue;
      }
      $validTags[] = $entity;
    }

    return $validTags;
  }

  /**
   * Extracts all the cashtags and the indices they occur at from the tweet.
   *
   * @return  array  The cashtag elements in the tweet.
   */
  public function extractCashtagsWithIndices() {
    if (!preg_match('/\$/iu', $this->tweet)) {
      return array();
    }

    preg_match_all(self::$patterns['valid_cashtag'], $this->tweet, $matches, PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
    $tags = array();

    foreach ($matches as $match) {
      list($all, $before, $dollar, $cash_text, $outer) = array_pad($match, 3, array('', 0));
      $start_position = $dollar[1] > 0 ? mb_strlen(substr($this->tweet, 0, $dollar[1])) : $dollar[1];
      $end_position = $start_position + mb_strlen($dollar[0] . $cash_text[0]);

      if (preg_match(self::$patterns['end_hashtag_match'], $outer[0])) {
        continue;
      }

      $tags[] = array(
          'cashtag' => $cash_text[0],
          'indices' => array($start_position, $end_position)
      );
    }

    return $tags;
  }

  /**
   * Extracts all the URLs and the indices they occur at from the tweet.
   *
   * @return  array  The URLs elements in the tweet.
   */
  public function extractURLsWithIndices() {
    $needle = $this->extractURLWithoutProtocol() ? '.' : ':';
    if (strpos($this->tweet, $needle) === false) {
      return array();
    }

    $urls = array();
    preg_match_all(self::$patterns['valid_url'], $this->tweet, $matches, PREG_SET_ORDER|PREG_OFFSET_CAPTURE);

    foreach ($matches as $match) {
      list($all, $before, $url, $protocol, $domain, $port, $path, $query) = array_pad($match, 8, array(''));
      $start_position = $url[1] > 0 ? mb_strlen(substr($this->tweet, 0, $url[1])) : $url[1];
      $end_position = $start_position + mb_strlen($url[0]);

      $all = $all[0];
      $before = $before[0];
      $url = $url[0];
      $protocol = $protocol[0];
      $domain = $domain[0];
      $port = $port[0];
      $path = $path[0];
      $query = $query[0];

      // If protocol is missing and domain contains non-ASCII characters,
      // extract ASCII-only domains.
      if (empty($protocol)) {
        if (!$this->extractURLWithoutProtocol
          || preg_match(self::$patterns['invalid_url_without_protocol_match_begin'], $before)) {
          continue;
        }

        $last_url = null;
        $last_url_invalid_match = false;
        $ascii_end_position = 0;
        if (preg_match(self::$patterns['invalid_url_without_protocol_match_begin'], $before)) {
          continue;
        }

        if (preg_match(self::$patterns['valid_ascii_domain'], $domain, $asciiDomain)) {
          $asciiDomain[0] = preg_replace('/' . preg_quote($domain, '/') . '/u', $asciiDomain[0], $url);
          $ascii_start_position = mb_strpos($domain, $asciiDomain[0], $ascii_end_position);
          $ascii_end_position = $ascii_start_position + mb_strlen($asciiDomain[0]);
          $last_url = array(
              'url' => $asciiDomain[0],
              'indices' => array($start_position + $ascii_start_position, $start_position + $ascii_end_position),
          );
          $last_url_invalid_match = preg_match(self::$patterns['invalid_short_domain'], $asciiDomain[0]);
          if (!$last_url_invalid_match) {
            $urls[] = $last_url;
          }
        }

        // no ASCII-only domain found. Skip the entire URL
        if (empty($last_url)) {
          continue;
        }

        // $last_url only contains domain. Need to add path and query if they exist.
        if (!empty($path) && $last_url_invalid_match) {
          // last_url was not added. Add it to urls here.
          $last_url['url'] = preg_replace('/' . preg_quote($domain, '/') . '/u', $last_url['url'], $url);
          $last_url['indices'][1] = $end_position;
          $urls[] = $last_url;
        }
      } else {
        // In the case of t.co URLs, don't allow additional path characters
        if (preg_match(self::$patterns['valid_tco_url'], $url, $tcoUrlMatches)) {
          $url = $tcoUrlMatches[0];
          $end_position = $start_position + mb_strlen($url);
        }
        $urls[] = array(
            'url' => $url,
            'indices' => array($start_position, $end_position),
        );
      }
    }

    return $urls;
  }

  /**
   * Extracts all the usernames and the indices they occur at from the tweet.
   *
   * @return  array  The username elements in the tweet.
   */
  public function extractMentionedScreennamesWithIndices() {
    $usernamesOnly = array();
    $mentions = $this->extractMentionedUsernamesOrListsWithIndices();
    foreach ($mentions as $mention) {
      if (isset($mention['list_slug'])) {
        unset($mention['list_slug']);
      }
      $usernamesOnly[] = $mention;
    }
    return $usernamesOnly;
  }

  /**
   * Extracts all the usernames and the indices they occur at from the tweet.
   *
   * @return  array  The username elements in the tweet.
   * @deprecated since version 1.1.0
   */
  public function extractMentionedUsernamesWithIndices() {
    return $this->extractMentionedScreennamesWithIndices();
  }

  /**
   * Extracts all the usernames and the indices they occur at from the tweet.
   *
   * @return  array  The username elements in the tweet.
   */
  public function extractMentionsOrListsWithIndices() {
    if (!preg_match('/[@＠]/iu', $this->tweet)) {
      return array();
    }

    preg_match_all(self::$patterns['valid_mentions_or_lists'], $this->tweet, $matches, PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
    $results = array();

    foreach ($matches as $match) {
      list($all, $before, $at, $username, $list_slug, $outer) = array_pad($match, 6, array('', 0));
      $start_position = $at[1] > 0 ? mb_strlen(substr($this->tweet, 0, $at[1])) : $at[1];
      $end_position = $start_position + mb_strlen($at[0]) + mb_strlen($username[0]);
      $entity = array(
          'screen_name' => $username[0],
          'list_slug' => $list_slug[0],
          'indices' => array($start_position, $end_position),
      );

      if (preg_match(self::$patterns['end_mention_match'], $outer[0])) {
        continue;
      }

      if (!empty($list_slug[0])) {
        $entity['indices'][1] = $end_position + mb_strlen($list_slug[0]);
      }

      $results[] = $entity;
    }

    return $results;
  }

  /**
   * Extracts all the usernames and the indices they occur at from the tweet.
   *
   * @return  array  The username elements in the tweet.
   * @deprecated since version 1.1.0
   */
  public function extractMentionedUsernamesOrListsWithIndices() {
    return $this->extractMentionsOrListsWithIndices();
  }

  /**
   * setter/getter for extractURLWithoutProtocol
   *
   * @param boolean $flag
   * @return \Twitter_Extractor
   */
  public function extractURLWithoutProtocol($flag = null) {
    if (is_null($flag)) {
      return $this->extractURLWithoutProtocol;
    }
    $this->extractURLWithoutProtocol = (bool)$flag;
    return $this;
  }

  /**
   * Remove overlapping entities.
   * This returns a new array with no overlapping entities.
   *
   * @param array $entities
   * @return array
   */
  protected function removeOverlappingEntities($entities) {
    $result = array();
    usort($entities, array($this, 'sortEntites'));

    $prev = null;
    foreach ($entities as $entity) {
      if (isset($prev) && $entity['indices'][0] < $prev['indices'][1]) {
        continue;
      }
      $prev = $entity;
      $result[] = $entity;
    }
    return $result;
  }

  /**
   * sort by entity start index
   *
   * @param array $a
   * @param array $b
   * @return int
   */
  protected function sortEntites($a, $b) {
    if ($a['indices'][0] == $b['indices'][0]) {
      return 0;
    }
     return ($a['indices'][0] < $b['indices'][0]) ? -1 : 1;
  }
}

################################################################################
# vim:et:ft=php:nowrap:sts=2:sw=2:ts=2
