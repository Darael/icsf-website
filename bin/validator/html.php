<?php

class HtmlValidator extends Validator
{
	/** @var array */
	public static $tags = array();
	/** @var array */
	protected $tagStack = array();

	protected function run($content)
	{
		$regex = '/\<(?<close>\/?)(?<tag>[a-z:!]+)(?<attrs>[^>]+)?(?<selfclose>\/?)\>/smU';
		$offset = 0;
		$match = array();

		while (preg_match($regex, $content, $match, PREG_OFFSET_CAPTURE, $offset))
		{
			$offset  = $match[0][1] + mb_strlen($match[0][0]);

			$match = array(
				'line' => substr_count($content, "\n", 0, $offset),
				'tag' => $match['tag'][0],
				'attrs' => $match['attrs'][0],
				'close' => !empty($match['close'][0]),
				'selfclose' => !empty($match['selfclose'][0])
			);

			if (!array_key_exists($match['tag'], self::$tags))
			{
				$this->error($match['line'], false, "Dropping unrecognised tag " + $match['tag']);
				continue;
			}

			if ($match['close'])
			{
				$this->close($match);
				continue;
			}

			$this->open($match);

			$match['attrs'] = $this->parseAttributes($match);
			$missing = self::$tags[$match['tag']]->getMissingAbbributes($match['attrs']);

			foreach ($missing as $missed)
			{
				$this->error($match['line'], false, sprintf('Tag %s missing attribute %s', $match['tag'], $missed));
			}
		}
	}

	protected function open(array $match)
	{
		$current = end($this->tagStack);

		if ($current === false)
		{
			if (!$match['selfclose'])
			{
				array_push($this->tagStack, $match['tag']);
			}
			return;
		}

		$current = self::$tags[$current];

		if ($current->isChildPermitted($match['tag']))
		{
			$this->error($match['line'], false, sprintf('Tag %s may not be a child of %s', $match['tag'], $current->getName()));
		}

		if (!$match['selfclose'])
		{
			array_push($this->tagStack, $match['tag']);
		}
	}

	protected function close(array $match)
	{
		$current = end($this->tagStack);

		if ($current === false)
		{
			$this->error($match['line'], false, sprintf('Tag %s closed when no tags are open', $match['tag']));
			return;
		}

		if ($match['tag'] !== $current)
		{
			$this->error($match['line'], false, sprintf('Tag %s attempting to close %s', $match['tag'], $current));

			// If the closing tag is a container for the last opened,
			// the close propigates up the stack
			if (array_key_exists($current, self::$tags[$match['tag']]->children))
			{
				array_pop($this->tagStack);
				$this->close($match);
			}
		}
		else
		{
			array_pop($this->tagStack);
		}
	}

	public function parseAttributes(array $match)
	{
		$regex = '/([a-z\-]+)=(["\'])([^\2]+)\2/smU';
		preg_match_all($regex, $content, $match);

		var_dump($match);
	}

}

$head  = array('title', 'link', 'meta', 'script', 'style');
$secs  = array('header', 'nav', 'footer', 'div');
$block = array('nav', 'div', 'ul', 'ol', 'p', 'table');
$bflow = array('h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img');
$flow  = array('span', 'a', 'em', 'strong', 'img');
$exts  = array('script', 'style');

HtmlValidator::$tags['html'] = new HtmlTag('html', array('head', 'body'));
HtmlValidator::$tags['head'] = new HtmlTag('head', $head);
HtmlValidator::$tags['body'] = new HtmlTag('body', $secs + $block + $bflow + $flow + $exts);

foreach ($head as $elem)
{
	HtmlValidator::$tags[$elem] = new HtmlTag($elem);
}

foreach ($secs as $elem)
{
	HtmlValidator::$tags[$elem] = new HtmlTag($elem, $block + $bflow + $flow + $exts);
}

foreach ($block as $elem)
{
	HtmlValidator::$tags[$elem] = new HtmlTag($elem, $block + $bflow + $flow + $exts);
}

foreach ($bflow as $elem)
{
	HtmlValidator::$tags[$elem] = new HtmlTag($elem, $bflow + $flow + $exts);
}

HtmlValidator::$tags['span'] = new HtmlTag('span', $flow + $exts);
HtmlValidator::$tags['a'] = new HtmlTag('a', $flow + $exts, array('href'));
HtmlValidator::$tags['img'] = new HtmlTag('img', array(), array('alt', 'src', 'width', 'height'));

HtmlValidator::$tags['ul'] = new HtmlTag('ul', array('li'));
HtmlValidator::$tags['ol'] = new HtmlTag('ol', array('li'));
HtmlValidator::$tags['li'] = new HtmlTag('li', $block + $bflow + $flow + $exts);

HtmlValidator::$tags['table'] = new HtmlTag('table', array('thead', 'tbody'));
HtmlValidator::$tags['thead'] = new HtmlTag('thead', array('tr'));
HtmlValidator::$tags['tbody'] = new HtmlTag('tbody', array('tr'));
HtmlValidator::$tags['tr'] = new HtmlTag('tr', array('td'));
HtmlValidator::$tags['td'] = new HtmlTag('tr', $block + $bflow + $flow + $exts);

unset($head, $sec, $block, $bflow, $flow, $exts);

class HtmlTag
{
	/** @var string */
	protected $name;
	/** @var array */
	protected $children;
	/** @var array */
	protected $attrs;

	public function __construct($name, array $children = array(), array $attrs = array())
	{
		$this->name = $name;
		$this->children = array_flip($children);
		$this->attrs = array_flip($attrs);
	}

	public function getName()
	{
		return $this->name;
	}

	public function isChildPermitted($child)
	{
		return array_key_exists($child, $this->children);
	}

	public function getMissingAbbributes(array $attrs)
	{
		return array_flip(array_diff_key($this->attrs, $attrs));
	}
}
