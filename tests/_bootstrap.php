<?php

namespace Smolblog\Test;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SebastianBergmann\Comparator\ComparisonFailure;
use Smolblog\Framework\Messages\Command;
use Smolblog\Framework\Messages\Event;
use Smolblog\Framework\Messages\MessageBus;
use Smolblog\Framework\Objects\Identifier;
use Smolblog\Framework\Objects\RandomIdentifier;

require_once __DIR__ . '/../vendor/autoload.php';

class TestCase extends PHPUnitTestCase {
	protected mixed $subject;

	protected function randomId(bool $scrub = false): Identifier {
		$id = new RandomIdentifier();

		return $scrub ? $this->scrubId($id) : $id;
	}

	protected function scrubId(Identifier $id): Identifier {
		return Identifier::fromByteString($id->toByteString());
	}
}

trait DateIdentifierTestKit {
	/**
	 * Asserts that two identifiers are created from the same date. A v7 UUID hashes the date, then adds random bytes.
	 * This function trims the random bytes and compares the remaining data.
	 */
	private function assertIdentifiersHaveSameDate(Identifier $expected, Identifier $actual) {
		$expectedTrim = substr(strval($expected), offset: 0, length: -8);
		$actualTrim = substr(strval($actual), offset: 0, length: -8);

		$this->assertEquals($expectedTrim, $actualTrim);
	}
}

trait EventComparisonTestKit {
	private function eventEquivalentTo(Event $expected): Constraint {
		return new EventIsEquivalent($expected);
	}
}

class EventIsEquivalent extends Constraint {
	public function __construct(private Event $expected) {}

	public function toString(): string { return 'two Events are equivalent'; }
	protected function failureDescription($other): string { return $this->toString(); }

	protected function matches($other): bool {
		if (!is_a($other, Event::class)) {
			throw new InvalidArgumentException('Object is not an Event.');
		}

		$expectedData = $this->expected->toArray();
		unset($expectedData['id']);
		unset($expectedData['timestamp']);

		$actualData = $other->toArray();
		unset($actualData['id']);
		unset($actualData['timestamp']);

		return $expectedData == $actualData;
	}

	protected function fail($other, $description, ?ComparisonFailure $comparisonFailure = null): void
	{
		if ($comparisonFailure === null) {
			$expectedData = $this->expected->toArray();
			unset($expectedData['id']);
			unset($expectedData['timestamp']);

			$actualData = $other->toArray();
			unset($actualData['id']);
			unset($actualData['timestamp']);

			$comparisonFailure = new ComparisonFailure(
				$this->expected,
				$other,
				$this->exporter()->export($expectedData),
				$this->exporter()->export($actualData),
				false,
				'Failed asserting that two Events are equivalent.'
			);
		}

		parent::fail($other, $description, $comparisonFailure);
	}
}

trait SerializableTestKit {
	public function testItWillSerializeToArrayAndDeserializeToItself() {
		$class = get_class($this->subject);
		$this->assertEquals($this->subject, $class::fromArray($this->subject->toArray()));
	}

	public function testItWillSerializeToJsonAndDeserializeToItself() {
		$class = get_class($this->subject);
		$this->assertEquals($this->subject, $class::jsonDeserialize(json_encode($this->subject)));
	}
}

trait ActivityPubActivityTestKit {
	use SerializableTestKit;

	public function testItGivesTheCorrectType() {
		$this->assertEquals($this->subject->type(), static::EXPECTED_TYPE);
	}
}

trait HttpMessageComparisonTestKit {
	private function httpMessageEqualTo(RequestInterface|ResponseInterface $expected): Constraint {
		return new HttpMessageIsEquivalent($expected);
	}
}

class HttpMessageIsEquivalent extends Constraint {
	public function __construct(private RequestInterface|ResponseInterface $expected) {}

	public function toString(): string { return 'two HTTP messages are equivalent'; }
	protected function failureDescription($other): string { return $this->toString(); }

	private function makeArray(RequestInterface|ResponseInterface $message): array {
		$data = ['type' => ''];

		foreach($message->getHeaders() as $key => $value) {
			$lowerKey = strtolower($key);
			$data[$lowerKey] = $value;
		}

		$bodyString = $message->getBody()->__toString();
		$data['body'] = empty($bodyString) ? null : $bodyString;

		if (is_a($message, RequestInterface::class)) {
			$data['type'] .= 'Request';
			$data['url'] = $message->getUri()->__toString();
			$data['method'] = $message->getMethod();
		}

		if (is_a($message, ResponseInterface::class)) {
			$data['type'] .= 'Response';
			$data['code'] = $message->getStatusCode();
		}

		return $data;
	}

	protected function matches($other): bool {
		if (!is_a($other, MessageInterface::class)) {
			throw new InvalidArgumentException('Object is not an HTTP Message.');
		}

		$expectedData = $this->makeArray($this->expected);
		$actualData = $this->makeArray($other);

		return $expectedData == $actualData;
	}

	protected function fail($other, $description, ?ComparisonFailure $comparisonFailure = null): void
	{
		if ($comparisonFailure === null) {
			$expectedData = $this->makeArray($this->expected);
			$actualData = $this->makeArray($other);

			$comparisonFailure = new ComparisonFailure(
				$this->expected,
				$other,
				$this->exporter()->export($expectedData),
				$this->exporter()->export($actualData),
				false,
				'Failed asserting that two HTTP messages are equivalent.'
			);
		}

		parent::fail($other, $description, $comparisonFailure);
	}
}
