<?php

declare(strict_types=1);

namespace Drupal\Tests\openid_connect_username_sanitizer\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Tests the username sanitisation logic against Drupal's own validation.
 *
 * @group openid_connect_username_sanitizer
 */
class UsernameSanitizerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'openid_connect_username_sanitizer',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['openid_connect_username_sanitizer']);
  }

  /**
   * Sanitised output must always pass Drupal's real username validation.
   *
   * This deliberately validates against \Drupal\user\Entity\User's actual
   * field validation, not just this module's own regex copy, so a
   * transcription mistake in the sanitiser can't mask itself.
   *
   * @dataProvider providerIllegalUsernames
   */
  public function testSanitizedOutputIsAlwaysAValidUsername(string $input): void {
    $sanitized = _openid_connect_username_sanitizer_sanitize_username($input);

    if ($sanitized === '') {
      // Nothing usable survived - covered separately below.
      return;
    }

    $account = User::create([
      'name' => $sanitized,
      'mail' => 'test-' . md5($sanitized) . '@example.com',
    ]);
    $violations = $account->get('name')->validate();

    $this->assertCount(
      0,
      $violations,
      sprintf(
        'Sanitised value "%s" (from "%s") should be a valid Drupal username, but got: %s',
        $sanitized,
        $input,
        $violations->count() ? (string) $violations->get(0)->getMessage() : ''
      )
    );
  }

  /**
   * Data provider of claim values a real identity provider might send.
   *
   * @return array<string, array<string>>
   */
  public static function providerIllegalUsernames(): array {
    return [
      'illegal square brackets' => ['[Org] Example Name'],
      'illegal comma' => ['Doe, Jane'],
      'illegal asterisks and hash' => ['*Jane* #Doe#'],
      'leading/trailing/double spaces plus illegal chars' => ['  Jane   [Doe]  '],
      'over-length with illegal characters throughout' => [
        '  A very long display name, with [some] illegal * characters # and ' . str_repeat('x', 80) . '  ',
      ],
      'already valid - should pass through unchanged in practice' => ['jane.doe@example.com'],
      'entirely illegal characters' => ['######'],
    ];
  }

  /**
   * A claim with nothing sanitisable left becomes an empty string.
   */
  public function testWhollyIllegalClaimSanitizesToEmptyString(): void {
    $this->assertSame('', _openid_connect_username_sanitizer_sanitize_username('######'));
  }

  /**
   * The hook alters $userinfo in place, leaving valid claims untouched.
   */
  public function testHookAltersIllegalClaimsAndLeavesValidOnesAlone(): void {
    $userinfo = [
      'name' => '[Org] Example Name',
      'preferred_username' => 'valid.username',
    ];

    openid_connect_username_sanitizer_openid_connect_userinfo_alter($userinfo, ['plugin_id' => 'test_client']);

    $this->assertSame('Org Example Name', $userinfo['name']);
    $this->assertSame('valid.username', $userinfo['preferred_username']);
  }

  /**
   * A wholly-illegal claim is unset entirely, not left as an empty string.
   */
  public function testHookUnsetsWhollyIllegalClaim(): void {
    $userinfo = ['name' => '######'];

    openid_connect_username_sanitizer_openid_connect_userinfo_alter($userinfo, ['plugin_id' => 'test_client']);

    $this->assertArrayNotHasKey('name', $userinfo);
  }

  /**
   * Disabling the module's config leaves claims completely untouched.
   */
  public function testDisabledConfigSkipsSanitisationEntirely(): void {
    $this->config('openid_connect_username_sanitizer.settings')->set('enabled', FALSE)->save();

    $userinfo = ['name' => '[Org] Example Name'];
    openid_connect_username_sanitizer_openid_connect_userinfo_alter($userinfo, ['plugin_id' => 'test_client']);

    $this->assertSame('[Org] Example Name', $userinfo['name']);
  }

  /**
   * hook_module_implements_alter() moves this module to run last, and only
   * for the one hook it cares about.
   */
  public function testModuleImplementsAlterOrdersThisModuleLast(): void {
    $implementations = [
      'module_a' => [],
      'openid_connect_username_sanitizer' => [],
      'module_b' => [],
    ];
    openid_connect_username_sanitizer_module_implements_alter($implementations, 'openid_connect_userinfo_alter');
    $this->assertSame(['module_a', 'module_b', 'openid_connect_username_sanitizer'], array_keys($implementations));

    $unrelated = [
      'module_a' => [],
      'openid_connect_username_sanitizer' => [],
      'module_b' => [],
    ];
    openid_connect_username_sanitizer_module_implements_alter($unrelated, 'some_other_hook_alter');
    $this->assertSame(['module_a', 'openid_connect_username_sanitizer', 'module_b'], array_keys($unrelated));
  }

}
