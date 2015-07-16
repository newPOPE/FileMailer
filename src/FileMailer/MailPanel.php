<?php

namespace RM;

use Nette\Utils\DateTime;
use Nette\Application\UI\Control;
use Nette\Caching\Cache;
use Nette\Caching\IStorage;
use Nette\Http\Response;
use Nette\Http\Request;
use Tracy\IBarPanel;


/**
 * Tracy bar panel showing e-mails stored by FileMailer.
 *
 * @author    Jan Drábek, Roman Mátyus
 * @copyright (c) Jan Drábek 2013
 * @copyright (c) Roman Mátyus 2013
 * @license   MIT
 * @package   FileMailer
 */
class MailPanel extends Control implements IBarPanel {

	/** @var Request */
	private $request;

	/** @var FileMailer */
	private $fileMailer;

	/** @var Cache */
	private $cache;

	/** @var integer */
	private $countAll = 0;

	/** @var integer */
	private $countNew = 0;

	/** @var array */
	private $messages = [];

	/** @var bool */
	private $processed = FALSE;

	/** @var string */
	public $newMessageTime = '-2 seconds';

	/** @var array */
	public $show = ['subject', 'from', 'to'];

	/** @var mixed */
	public $autoremove = '-15 seconds';

	/** @var bool */
	public $hideEmpty = TRUE;


	public function __construct(Request $request, IStorage $cacheStorage)
	{
		$this->request = $request;
		$this->cache = new Cache($cacheStorage, 'MailPanel');

		switch($request->getQuery('mail-panel')) {
			case 'download':
				$this->handleDownload($request->getQuery('mail-panel-mail'), $request->getQuery('mail-panel-file'));
				break;

			default:
				break;
		}
	}


	/**
	 * @param  FileMailer $fileMailer
	 * @return $this
	 */
	public function setFileMailer(FileMailer $fileMailer)
	{
		$this->fileMailer = $fileMailer;
		return $this;
	}


	/**
	 * Returns HTML code for Tracy bar icon.
	 * @return mixed
	 */
	public function getTab()
	{
		$this->processMessage();
		if ($this->countAll === 0 && $this->hideEmpty) {
			return;
		}

		return '<span title="FileMailer"><svg><path style="fill:#' . ( $this->countNew > 0 ? 'E90D0D' : '348AD2' ) . '" d="m 0.9 4.5 6.6 7 c 0 0 0 0 0 0 0.2 0.1 0.3 0.2 0.4 0.2 0.1 0 0.3 -0 0.4 -0.2 l 0 -0 L 15.1 4.5 0.9 4.5 z M 0 5.4 0 15.6 4.8 10.5 0 5.4 z m 16 0 L 11.2 10.5 16 15.6 16 5.4 z M 5.7 11.4 0.9 16.5 l 14.2 0 -4.8 -5.1 -1 1.1 -0 0 -0 0 c -0.4 0.3 -0.8 0.5 -1.2 0.5 -0.4 0 -0.9 -0.2 -1.2 -0.5 l -0 -0 -0 -0 -1 -1.1 z" /></svg><span class="tracy-label">'
			. ($this->countNew > 0 ? $this->countNew : NULL)
			. '</span></span>';
	}


	/**
	 * Returns HTML code of panel.
	 * @return mixed
	 */
	public function getPanel()
	{
		if ($this->countAll === 0 && $this->hideEmpty) {
			return;
		}

		$this->processMessage();

		$latte = new \Latte\Engine;
		$latte->setTempDirectory($this->fileMailer->getTempDirectory());
		return $latte->renderToString(__DIR__ . '/MailPanel.latte', [
			'messages' => $this->messages,
			'countNew' => $this->countNew,
			'countAll' => $this->countAll,
			'show' => $this->show,
		]);
	}


	/**
	 * Process all messages.
	 */
	private function processMessage()
	{
		if ($this->processed) {
			return;
		}
		$this->processed = TRUE;

		$files = glob($this->fileMailer->getTempDirectory() . DIRECTORY_SEPARATOR . '*.' . FileMailer::FILE_EXTENSION);
		foreach ($files as $path) {
			$cacheKey = basename($path);

			if ($this->removeExpired($path)) {
				$this->cache->remove($cacheKey);
				continue;
			}

			$message = $this->cache->load($cacheKey);
			if ($message === NULL) {
				$message = FileMailer::mailParser(file_get_contents($path), $cacheKey);
				$this->cache->save($cacheKey, $message);
			}

			$time = new DateTime;
			if ($message->date > $time->modify($this->newMessageTime)) {
				$this->countNew++;
			} else {
				$message->isOld = TRUE;
			}

			$this->countAll++;
			$this->messages[] = $message;
		}

		usort($this->messages, function ($a1, $a2) {
			return $a2->date->getTimestamp() - $a1->date->getTimestamp();
		});
	}


	/**
	 * Auto removes email file when expired.
	 * @param  string
	 * @return bool
	 */
	private function removeExpired($path)
	{
		if ($this->autoremove) {
			$now = new DateTime;
			$file_date = new DateTime('@' . filemtime($path));
			$file_date->setTimezone($now->getTimezone());
			$remove_date = $now->modify($this->autoremove);
			if ($file_date < $remove_date) {
				unlink($path);
				return TRUE;
			}
		}

		return FALSE;
	}


	/**
	 * Download attachment from file.
	 */
	public function handleDownload($filename, $filehash)
	{
		$message = $this->cache->load($filename);
		$file = $message->attachments[$filehash];
		header("Content-Type: $file->type; charset='UTF-8'");
		header("Content-Transfer-Encoding: base64");
		header("Content-Disposition: attachment; filename=\"$file->filename\"");
		header('Content-Length: ' . strlen($file->data));
		echo base64_decode($file->data);
		exit;
	}

}
