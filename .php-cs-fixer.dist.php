<?php

declare(strict_types=1);

/*
 * PHP-CS-Fixer Configuration for RegexParser
 *
 * This configuration provides the highest quality code formatting for modern PHP libraries,
 * focusing on readability, consistency, performance, and modern PHP best practices.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 */

$header = <<<'EOF'
This file is part of the PHPRegex package.

(c) Younes ENNAJI <younes.ennaji.pro@gmail.com>

For the full copyright and license information, please view the LICENSE
file that was distributed with this source code.
EOF;

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__.'/bin',
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/benchmarks',
    ])
    ->exclude('Fixtures')
    // Laravel bridge uses facades which must be called statically
    ->exclude('Bridge/Laravel')
    ->notPath('Unit/Cache/FilesystemCacheTest.php')
    ->notPath('Unit/Lint/TokenBasedExtractionStrategyTest.php')
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->registerCustomFixers(new PhpCsFixerCustomFixers\Fixers())
    ->setRiskyAllowed(true)
    ->setRules([
        'psr_autoloading' => true,
        '@PHP8x2Migration' => true,
        '@PHP8x2Migration:risky' => true,
        '@PSR12' => true,
        '@PSR12:risky' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHPUnit10x0Migration:risky' => true,
        'array_syntax' => ['syntax' => 'short'],
        'class_attributes_separation' => [
            'elements' => [
                'property' => 'one',
                'method' => 'one',
            ],
        ],
        'header_comment' => ['header' => $header],
        'combine_consecutive_unsets' => true,
        'declare_strict_types' => true,
        'doctrine_annotation_indentation' => true,
        'doctrine_annotation_spaces' => true,
        'final_class' => false,
        'random_api_migration' => false,
        'binary_operator_spaces' => [
            'default' => 'single_space',
            'operators' => ['=>' => 'single_space'],
        ],
        'general_phpdoc_annotation_remove' => [
            'annotations' => [
                'copyright',
                'category',
            ],
        ],
        'heredoc_to_nowdoc' => true,
        'modernize_strpos' => false,
        'modernize_types_casting' => true,
        'native_constant_invocation' => ['strict' => false],
        'native_function_invocation' => false,
        'no_alias_functions' => true,
        'no_superfluous_phpdoc_tags' => true,
        'no_unreachable_default_argument_value' => true,
        'no_unused_imports' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'operator_linebreak' => ['only_booleans' => true],
        'ordered_class_elements' => true,
        'ordered_interfaces' => true,
        'ordered_traits' => true,
        'php_unit_construct' => true,
        'php_unit_dedicate_assert' => true,
        'php_unit_dedicate_assert_internal_type' => true,
        'php_unit_mock' => true,
        'php_unit_mock_short_will_return' => true,
        'php_unit_strict' => false,
        'php_unit_test_case_static_method_calls' => false,
        'phpdoc_add_missing_param_annotation' => true,
        'phpdoc_line_span' => true,
        'phpdoc_order' => true,
        'phpdoc_scalar' => true,
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_summary' => false,
        'phpdoc_var_annotation_correct_order' => true,
        'phpdoc_var_without_name' => true,
        'protected_to_private' => true,
        'semicolon_after_instruction' => true,
        'single_line_throw' => false,
        'single_trait_insert_per_statement' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'ternary_to_null_coalescing' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arguments']],
        'unary_operator_spaces' => true,
         'phpdoc_to_comment' => [
             'ignored_tags' => ['var'],
         ],
        'array_indentation' => true,
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => true,
        'blank_line_before_statement' => true,
        'blank_line_between_import_groups' => true,
        'blank_lines_before_namespace' => true,
        'cast_spaces' => true,
        'class_definition' => [
            'multi_line_extends_each_single_line' => true,
            'single_item_single_line' => true,
            'single_line' => true,
        ],
        'clean_namespace' => true,
        'compact_nullable_type_declaration' => true,
        'concat_space' => ['spacing' => 'none'],
        'constant_case' => ['case' => 'lower'],
        'control_structure_braces' => true,
        'control_structure_continuation_position' => ['position' => 'same_line'],
        'declare_equal_normalize' => true,
        'declare_parentheses' => true,
        'elseif' => true,
        'encoding' => true,
        'full_opening_tag' => true,
        'fully_qualified_strict_types' => false,
        'function_declaration' => true,
        'general_phpdoc_tag_rename' => true,
        'include' => true,
        'increment_style' => ['style' => 'post'],
        'indentation_type' => true,
        'integer_literal_case' => true,
        'lambda_not_used_import' => true,
        'line_ending' => true,
        'linebreak_after_opening_tag' => true,
        'list_syntax' => true,
        'lowercase_cast' => true,
        'lowercase_keywords' => true,
        'lowercase_static_reference' => true,
        'magic_constant_casing' => true,
        'magic_method_casing' => true,
        'method_chaining_indentation' => false,
        'multiline_whitespace_before_semicolons' => ['strategy' => 'no_multi_line'],
        'native_function_casing' => true,
        'native_type_declaration_casing' => true,
        'no_alias_language_construct_call' => true,
        'no_alternative_syntax' => true,
        'no_binary_string' => true,
        'no_blank_lines_after_class_opening' => true,
        'no_blank_lines_after_phpdoc' => true,
        'no_closing_tag' => true,
        'no_empty_phpdoc' => true,
        'no_empty_statement' => true,
        'no_extra_blank_lines' => [
            'tokens' => [
                'extra',
                'throw',
                'use',
            ],
        ],
        'no_leading_import_slash' => true,
        'no_leading_namespace_whitespace' => true,
        'no_mixed_echo_print' => ['use' => 'echo'],
        'no_multiline_whitespace_around_double_arrow' => true,
        'no_multiple_statements_per_line' => true,
        'no_short_bool_cast' => true,
        'no_singleline_whitespace_before_semicolons' => true,
        'no_space_around_double_colon' => true,
        'no_spaces_after_function_name' => true,
        'no_spaces_around_offset' => [
            'positions' => [
                'inside',
                'outside',
            ],
        ],
        'no_trailing_comma_in_singleline' => true,
        'no_trailing_whitespace' => true,
        'no_trailing_whitespace_in_comment' => true,
        'no_unneeded_control_parentheses' => [
            'statements' => [
                'break',
                'clone',
                'continue',
                'echo_print',
                'return',
                'switch_case',
                'yield',
            ],
        ],
        'no_unneeded_braces' => true,
        'no_unset_cast' => true,
        'no_whitespace_before_comma_in_array' => true,
        'no_whitespace_in_blank_line' => true,
        'normalize_index_brace' => true,
        'nullable_type_declaration' => true,
        'object_operator_without_whitespace' => true,
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => ['const', 'class', 'function'],
        ],
        'php_unit_method_casing' => ['case' => 'snake_case'],
        'php_unit_set_up_tear_down_visibility' => true,
        'phpdoc_indent' => true,
        'phpdoc_inline_tag_normalizer' => true,
        'phpdoc_no_access' => true,
        'phpdoc_no_package' => true,
        'phpdoc_no_useless_inheritdoc' => true,
        'phpdoc_trim' => true,
        'phpdoc_types' => true,
        'return_type_declaration' => ['space_before' => 'none'],
        'self_accessor' => false,
        'self_static_accessor' => true,
        'short_scalar_cast' => true,
        'simplified_null_return' => false,
        'single_blank_line_at_eof' => true,
        'single_class_element_per_statement' => [
            'elements' => [
                'const',
                'property',
            ],
        ],
        'single_import_per_statement' => true,
        'single_line_after_imports' => true,
        'single_line_comment_style' => ['comment_types' => ['hash']],
        'single_line_empty_body' => true,
        'single_quote' => true,
        'single_space_around_construct' => true,
        'space_after_semicolon' => true,
        'spaces_inside_parentheses' => true,
        'standardize_not_equals' => true,
        'statement_indentation' => true,
        'switch_case_semicolon_to_colon' => true,
        'switch_case_space' => true,
        'ternary_operator_spaces' => true,
        'trim_array_spaces' => true,
        'type_declaration_spaces' => true,
        'types_spaces' => true,
        'modifier_keywords' => [
            'elements' => [
                'method',
                'property',
            ],
        ],
        'whitespace_after_comma_in_array' => true,
        'attribute_empty_parentheses' => true,
        'php_unit_attributes' => true,
        'phpdoc_param_order' => true,
        'phpdoc_array_type' => true,
        'multiline_promoted_properties' => [
            'keep_blank_lines' => true,
            'minimum_number_of_parameters' => 3,
        ],
        'class_reference_name_casing' => true,
        PhpCsFixerCustomFixers\Fixer\ForeachUseValueFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\FunctionParameterSeparationFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\MultilineCommentOpeningClosingAloneFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\NoDoctrineMigrationsGeneratedCommentFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\NoDuplicatedArrayKeyFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\NoDuplicatedImportsFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\NoImportFromGlobalNamespaceFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\NoPhpStormGeneratedCommentFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\NoSuperfluousConcatenationFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\NoTrailingCommaInSinglelineFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\NoUselessCommentFixer::name() => true,
        // PhpCsFixerCustomFixers\Fixer\NoUselessDirnameCallFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\NoUselessDoctrineRepositoryCommentFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\NoUselessParenthesisFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\NoUselessStrlenFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\NoUselessWriteVisibilityFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\PhpUnitAssertArgumentsOrderFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\PhpUnitDedicatedAssertFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\PhpUnitNoUselessReturnFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\PhpUnitRequiresConstraintFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\PhpdocNoIncorrectVarAnnotationFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\PhpdocParamTypeFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\PhpdocSelfAccessorFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\PhpdocTypesCommaSpacesFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\PhpdocTypesTrimFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\SingleSpaceAfterStatementFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\SingleSpaceBeforeStatementFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\StringableInterfaceFixer::name() => true,
        PhpCsFixerCustomFixers\Fixer\TrimKeyFixer::name() => true,
    ])
    ->setFinder($finder)
    ->setCacheFile('.cache/php-cs-fixer/cache.json');
