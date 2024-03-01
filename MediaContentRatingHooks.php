<?php

use MediaWiki\MediaWikiServices;
use PageImages\PageImageCandidate;

/**
 * MediaContentRating
 *
 * @author Arumi
 */

class MediaContentRatingHooks
{
	/**
	 * @var array[string]string Content rating alias
	 */
	private static $msgs = [
		'R15' => 'R15,R-15,R 15,15',
		'R18' => 'R18,R-18,R 18,18',
		'R18G' => 'R18G,R-18G,R 18G,18G',
	];

	/**
	 * Handles user preferences
	 *
	 * @param User $user
	 * @param array &$preferences
	 */
	public static function onGetPreferences(User $user, array &$preferences)
	{
		if ($user->isAnon() || $user->getBlock()) {
			return true;
		}

		$option = [
			wfMessage('content-rating-show')->text() => '1',
			wfMessage('content-rating-hide')->text() => '0',
		];

		foreach (self::$msgs as $rate => $msg) {
			$name = 'cr-allow-' . strtolower($rate);
			$preferences[$name] = [
				'type' => 'radio',
				'label-message' => 'content-rating-' . strtolower($rate),
				'section' => 'rendering/content-rating',
				'options' => $option,
				'flatlist' => true,
				'default' => MediaWikiServices::getInstance()->getUserOptionsLookup()->getOption($user, $name, false) ? '1' : '0',
			];
		}
		return true;
	}

	/**
	 * Hook to load our parser function.
	 *
	 * @param Parser $parser the Parser object
	 */
	public static function onParserFirstCallInit(Parser $parser)
	{
		$parser->setFunctionHook('rating', [__CLASS__, 'rating']);

		$parser->setHook('rating', [__CLASS__, 'ratingTag']);
	}

	/**
	 * Add content rating to media
	 *
	 * @param Parser $parser the Parser object
	 * @param string $text rating string
	 * @return string normalized rating string
	 */
	public static function rating(Parser $parser, $text = '')
	{
		$output = $parser->getOutput();
		$rate = self::mapContentRating($text);

		if ($rate !== '') {
			$output->setPageProperty('content-rating', $rate);
		} else {
			$output->unsetPageProperty('content-rating');
		}

		return $rate;
	}

	/**
	 * Add a start or end marker to content
	 *
	 * @param string|null $in The text inside the tag
	 * @param string[] $param
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string
	 */
	public static function ratingTag($in, array $param, Parser $parser, PPFrame $frame)
	{
		$startRating = isset($param['start']) ? strtolower(self::mapContentRating($param['start'])) : '';
		$endRating = isset($param['end']) ? strtolower(self::mapContentRating($param['end'])) : '';

		return($endRating !== '' ? '<rating-end-' . $endRating . '></rating-end-' . $endRating . '>' : '') .
			($startRating !== '' ? '<rating-start-' . $startRating . '></rating-start-' . $startRating . '>' : '');
	}

	/**
	 * Remove restricted image from page output
	 *
	 * @param OutputPage $output the OutputPage object
	 * @param string &$text
	 * @return bool Always <code>true</code>
	 */
	public static function onOutputPageBeforeHTML(OutputPage $output, string &$text)
	{
		$user = $output->getUser();

		if (!($user instanceof User) || $user->isAnon()) {
			$text = preg_replace('/<rating-start-([^>]*)><\\/rating-start-\\1>.*?<rating-end-\\1><\\/rating-end-\\1>/s', '', $text);
			$text = preg_replace('/<!--cr-.*?-cr-->/s', '', $text);
			return true;
		}

		$userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();

		foreach (self::$msgs as $rate => $msg) {
			$rate = strtolower($rate);
			$name = 'cr-allow-' . $rate;
			if ($userOptionsLookup->getOption($user, $name, false)) {
				$text = str_replace([
					'<rating-start-' . $rate . '></rating-start-' . $rate . '>',
					'<rating-end-' . $rate . '></rating-end-' . $rate . '>',
					'<!--cr-' . $rate . '-',
					'-' . $rate . '-cr' . '-->',
				], '', $text);
			} else {
				$text = preg_replace('/<rating-start-' . $rate . '><\\/rating-start-' . $rate . '>.*?<rating-end-' . $rate . '><\\/rating-end-' . $rate . '>/s', '', $text);
				$text = preg_replace('/<!--cr-' . $rate . '.*?-' . $rate . '-cr-->/s', '', $text);
			}
		}

		return true;
	}

	/**
	 * Wrap restricted image when outputting page
	 *
	 * @param DummyLinker &$linker
	 * @param Title &$title title of the image
	 * @param File &$file file of the image
	 * @param array &$frameParams
	 * @return bool Always <code>true</code>
	 */
	public static function onImageBeforeProduceHTML(DummyLinker &$linker, Title &$title, &$file, array &$frameParams)
	{
		$rate = self::getContentRating($title);
		if (!empty($rate)) {
			$frameParams['prefix'] = '<!--cr-' . strtolower($rate) . '-';
			$frameParams['postfix'] = '-' . strtolower($rate) . '-cr-->';
		}

		return true;
	}

	/**
	 * Display restricted image as a warning image
	 *
	 * @param ImagePage $imagePage
	 * @param OutputPage $output
	 * @return bool Always <code>true</code>
	 */
	public static function onImageOpenShowImageInlineBefore(ImagePage $imagePage, OutputPage $output)
	{
		$mediaContentRatingWarningImage = $imagePage->getContext()
			->getConfig()
			->get('MediaContentRatingWarningImage');

		$file = $imagePage->getDisplayedFile();
		$rate = self::getContentRating($file);

		if (self::isUserAllowed($imagePage->getContext()->getUser(), $rate)) {
			return true;
		}
		$imagePage->setFile(MediaWikiServices::getInstance()->getRepoGroup()->findFile($mediaContentRatingWarningImage));

		return true;
	}

	/**
	 * Remove restricted image from gallery
	 *
	 * @param Title &$title
	 * @param string &$html
	 * @param string &$alt
	 * @param string &$link
	 * @param array &$handlerOpts
	 * @return bool Always <code>true</code>
	 */
	public static function onBeforeAddToGallery(&$title, &$html, &$alt, &$link, &$handlerOpts)
	{
		$user = RequestContext::getMain()->getUser();
		$rate = self::getContentRating($title);
		return self::isUserAllowed($user, $rate);
	}

	/**
	 * Remove restricted image from image list
	 *
	 * @param ImageListPager $imageListPager
	 * @param File $file
	 * @param string &$html
	 * @return bool
	 */
	public static function onBeforeAddToImageList(ImageListPager $imageListPager, File $file, string &$html)
	{
		$user = $imageListPager->getUser();
		$rate = self::getContentRating($file);
		if (!self::isUserAllowed($user, $rate)) {
			$html = '';
			return false;
		}

		return true;
	}

	/**
	 * Prevent restricted image from chosen as page images
	 *
	 * @param PageImageCandidate $image Associative array describing an image
	 * @param int $position Image order on page
	 * @param float &$score Score for image
	 * @return bool Always <code>true</code>
	 */
	public static function onPageImagesGetScore(PageImageCandidate $image, $position, &$score)
	{
		if (!empty(self::getContentRating(MediaWikiServices::getInstance()->getRepoGroup()->findFile($image->getFileName())))) {
			$score = -1000;
		}

		return true;
	}

	/**
	 * @param File|Title|string $text
	 * @return string
	 */
	public static function getContentRating($text)
	{
		static $cache = [];

		if ($text instanceof File) {
			$id = $text->getTitle()->getArticleID();
		} elseif ($text instanceof Title) {
			$id = $text->getArticleID();
		} else {
			$title = Title::newFromText($text);
			$id = $title instanceof Title ? $title->getArticleID() : 0;
		}
		if ($id == 0) {
			return false;
		}

		if (isset($cache[$id])) {
			return $cache[$id];
		}

		$dbr = wfGetDB(DB_REPLICA);

		$cache[$id] = $dbr->selectField(
			'page_props',
			'pp_value',
			['pp_propname' => 'content-rating', 'pp_page' => $id]
		);

		return $cache[$id];
	}

	/**
	 * @param User $user
	 * @param string|bool $rate
	 * @return bool
	 */
	private static function isUserAllowed(User $user, $rate = false)
	{
		if (empty($rate)) {
			return true;
		}
		if (!($user instanceof User) || $user->isAnon()) {
			return false;
		}

		return (bool) MediaWikiServices::getInstance()->getUserOptionsLookup()->getOption($user, 'cr-allow-' . strtolower($rate), false);
	}

	/**
	 * @param string $text
	 * @return string
	 */
	private static function mapContentRating($text = '')
	{
		static $map = null;
		$text = trim($text);
		if ($text === '') {
			return '';
		}

		if ($map == null) {
			$map = [];
			foreach (self::$msgs as $rate => $msg) {
				foreach (explode(',', $msg) as $name) {
					$name = trim($name);
					if ($name !== '') {
						$map[$name] = $rate;
					}
				}
			}
		}
		$text = strtoupper($text);

		if (isset($map[$text])) {
			return $map[$text];
		} else {
			return '';
		}
	}
}
