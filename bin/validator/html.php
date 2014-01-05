<?php

class HtmlValidator extends Validator
{
	/** @var array */
	public static $tags = array();
	/** @var string */
	protected static $uriRebase = null;
	/** @var array */
	protected static $fileCache = array();
	/** @var array */
	protected $tagStack = array();
	/** @var int */
	protected $title = 0;

	public static function cache()
	{
		$cache = self::$fileCache;
		$cache = serialize($cache);
		file_put_contents(__DIR__ . '/cache', $cache);
	}

	protected function run($content)
	{
		if (empty(self::$uriRebase))
		{
			$srvroot = getenv('SRVROOT') ?: '/';
			$docroot = getenv('DOCROOT') ?: realpath(__DIR__ . '/../../build') . '/';

			self::$uriRebase = array(sprintf(':^%s:', $srvroot), $docroot);

			$cache = @file_get_contents(__DIR__ . '/cache');
			if (!empty($cache))
				self::$fileCache = unserialize($cache);

			register_shutdown_function(array('HtmlValidator', 'cache'));
		}

		$regex = '/\<(?<close>\/)?(?<tag>[a-zA-Z0-9:!]+)(?<attrs>[ \t\r\n][^>]+)?(?<selfclose> ?\/)?\>/smU';
		$offset = 0; $match = array();

		while (preg_match($regex, $content, $match, PREG_OFFSET_CAPTURE, $offset))
		{
			$end = $match[0][1] + strlen($match[0][0]);

			$match = array_merge(array('attrs'=>array('')), $match);
			$match = array(
				'line' => 1 + substr_count($content, "\n", 0, $match[0][1] + 1),
				'tag' => strtolower($match['tag'][0]),
				'attrs' => $match['attrs'][0],
				'close' => !empty($match['close'][0]),
				'selfclose' => !empty($match['selfclose'][0])
			);

			if ($offset === 0 && $match['tag'] === '!doctype')
			{
				$offset = $end;
				continue;
			}

			$offset = $end;

			if (!array_key_exists($match['tag'], self::$tags))
			{
				$this->error($match['line'], false, 'Dropping unrecognised tag "' . $match['tag'] . '"');
				continue;
			}

			if ($match['close'])
			{
				$this->close($match);
				continue;
			}

			$this->open($match);

			if ($match['tag'] === 'title')
				++$this->title;

			$match['attrs'] = $this->parseAttributes($match);
			$missing = self::$tags[$match['tag']]->getMissingAbbributes($match['attrs']);

			$this->checkAttributes($match['attrs'], $match['tag'], $match['line']);

			foreach ($missing as $missed)
			{
				$this->error($match['line'], true, sprintf('Tag "%s" missing attribute "%s"', $match['tag'], $missed));
			}
		}

		if ($this->title === 0)
			$this->error(0, true, 'HTML Documents require a <title> tag');
		if ($this->title > 1)
			$this->error(0, true, 'Multiple <title> tags found');
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

		if (!$current->isChildPermitted($match['tag']))
		{
			$this->error($match['line'], false, sprintf('Tag "%s" may not be a child of "%s"', $match['tag'], $current->getName()));
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
			$this->error($match['line'], false, sprintf('Tag "%s" closed when no tags are open', $match['tag']));
			return;
		}

		if ($match['tag'] !== $current)
		{
			$this->error($match['line'], false, sprintf('Tag "%s" attempting to close "%s"', $match['tag'], $current));

			// If the closing tag is a container for the last opened,
			// the close propigates up the stack
			if (self::$tags[$match['tag']]->isChildPermitted($current))
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

	protected function parseAttributes(array $match)
	{
		$regex = '/([a-z\-]+)=(["\'])([^\2]+)\2/smU';
		$attrs = $res = array();
		if (!preg_match_all($regex, $match['attrs'], $attrs, PREG_SET_ORDER))
		{
			return $res;
		}

		foreach ($attrs as $attr)
		{
			$res[$attr[1]] = $attr[3];
		}

		return $res;
	}

	protected function checkAttributes(array $attrs, $tag, $line)
	{
		if ($attrs === null)
			return;

		if (array_key_exists('href', $attrs))
		{
			list($uri, $exists) = $this->checkUri($attrs['href']);
			if (!$exists)
			{
				$this->error($line, true, sprintf('Link to "%s" not found, was resolved to "%s"', $attrs['href'], $uri));
			}
		}
		if (array_key_exists('src', $attrs))
		{
			list($uri, $exists) = $this->checkUri($attrs['src']);
			if (!$exists)
			{
				$this->error($line, true, sprintf('Link to "%s" not found, was resolved to "%s"', $attrs['src'], $uri));
			}
		}

		if ($tag === 'meta' && array_key_exists('name', $attrs))
		{
			if (!in_array(strtolower($attrs['name']), array('description', 'keywords', 'reply-to', 'authors')))
			{
				$this->error($line, true, sprintf('Meta field "%s" not recognised', $attrs['name']));
			}
		}
		if ($tag === 'meta' && array_key_exists('http-equiv', $attrs))
		{
			if (!in_array($attrs['http-equiv'], array('Content-Type')))
			{
				$this->error($line, true, sprintf('Meta field "%s" not recognised', $attrs['name']));
			}
		}
	}

	protected function checkUri($uri)
	{
		if (substr($uri, 0, 7) === 'mailto:')
			return array($uri, true);

		if (substr($uri, 0, 1) === '/')
		{
			if (strpos($uri, '/old/'))
			{
				return array($uri, true);
			}

			$uri = preg_replace(self::$uriRebase[0], self::$uriRebase[1], $uri);
			$uri = preg_replace('/[?#].+$/', '', $uri);
		}
		elseif (substr($uri, 0, 1) === '?' || substr($uri, 0, 1) === '#')
		{
			return array($uri, true);
		}
		elseif (strpos($uri, '://'))
		{
			if (array_key_exists($uri, self::$fileCache))
			{
				return array($uri, self::$fileCache[$uri]);
			}

			$fh = @fopen($uri, 'rb');

			if (false === $fh)
			{
				self::$fileCache[$uri] = false;
				return array($uri, false);
			}

			fclose($fh);

			self::$fileCache[$uri] = true;
			return array($uri, true);
		}
		else
		{
			$dir = dirname($this->file);
			$uri = $dir . '/' . $uri;
		}

		if (substr($uri, -1, 0) === '/')
		{
			return array($uri, file_exists($uri) || file_exists($uri . 'index.html') || file_exists($uri . 'index.php'));
		}

		return array($uri, file_exists($uri));
	}
}

$head  = array('title', 'link', 'meta', 'script', 'style');
$secs  = array('header', 'nav', 'footer', 'div');
$block = array('nav', 'div', 'ul', 'ol', 'p', 'table');
$bflow = array('h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote');
$flow  = array('span', 'a', 'em', 'strong', 'b', 'i', 'u', 'hr', 'br', 'img', 'sub', 'sup', 'iframe', 'del');
$exts  = array('script', 'style');

HtmlValidator::$tags['html'] = new HtmlTag('html', array('head', 'body'));
HtmlValidator::$tags['head'] = new HtmlTag('head', $head);
HtmlValidator::$tags['body'] = new HtmlTag('body', array_merge($secs, $block, $bflow, $flow, $exts));

foreach ($head as $elem)
{
	HtmlValidator::$tags[$elem] = new HtmlTag($elem);
}

foreach ($secs as $elem)
{
	HtmlValidator::$tags[$elem] = new HtmlTag($elem, array_merge($block, $bflow, $flow, $exts));
}

foreach ($block as $elem)
{
	HtmlValidator::$tags[$elem] = new HtmlTag($elem, array_merge($block, $bflow, $flow, $exts));
}

foreach ($bflow as $elem)
{
	HtmlValidator::$tags[$elem] = new HtmlTag($elem, array_merge($bflow, $flow, $exts));
}

foreach ($flow as $elem)
{
	HtmlValidator::$tags[$elem] = new HtmlTag($elem, array_merge($flow));
}

HtmlValidator::$tags['a'] = new HtmlTag('a', array_merge($flow, $exts), array('href'));
HtmlValidator::$tags['img'] = new HtmlTag('img', array(), array('alt', 'src', 'width', 'height'));

HtmlValidator::$tags['hr'] = new HtmlTag('hr');
HtmlValidator::$tags['br'] = new HtmlTag('br');

HtmlValidator::$tags['ul'] = new HtmlTag('ul', array('li'));
HtmlValidator::$tags['ol'] = new HtmlTag('ol', array('li'));
HtmlValidator::$tags['li'] = new HtmlTag('li', array_merge($block, $bflow, $flow, $exts));

HtmlValidator::$tags['table'] = new HtmlTag('table', array('thead', 'tbody', 'tr'));
HtmlValidator::$tags['thead'] = new HtmlTag('thead', array('tr'));
HtmlValidator::$tags['tbody'] = new HtmlTag('tbody', array('tr'));
HtmlValidator::$tags['tr'] = new HtmlTag('tr', array('td', 'th'));
HtmlValidator::$tags['td'] = new HtmlTag('td', array_merge($block, $bflow, $flow, $exts));
HtmlValidator::$tags['th'] = new HtmlTag('th', array_merge($block, $bflow, $flow, $exts));

HtmlValidator::$tags['iframe'] = new HtmlTag('iframe', array(), array('src'));

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

