<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\CategoryDraft;
use App\Form\DataTransformer\CommaSeparatedToArrayTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class CategoryType extends AbstractType
{
    public function __construct(private CommaSeparatedToArrayTransformer $transformer)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('existingListCoordinate', TextType::class, [
                'label' => 'Use existing list (optional)',
                'required' => false,
                'empty_data' => '',
                'help' => 'To attach an existing reading list, enter its coordinate in format: 30040:pubkey:slug. Leave empty to create a new category below.',
                'attr' => [
                    'placeholder' => 'Example: 30040:abc123...:my-list-slug',
                ],
            ])
            ->add('title', TextType::class, [
                'label' => 'Category title',
                'required' => false,
                'help' => 'Only needed if creating a new category (ignored for existing lists)',
            ])
            ->add('summary', TextType::class, [
                'label' => 'Summary',
                'required' => false,
                'empty_data' => '',
            ])
            ->add('image', TextType::class, [
                'label' => 'Cover image URL',
                'required' => false,
                'empty_data' => '',
            ])
            ->add('tags', TextType::class, [
                'label' => 'Tags (comma separated)',
                'required' => false,
                'empty_data' => '',
            ]);

        $builder->get('tags')->addModelTransformer($this->transformer);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CategoryDraft::class,
            'constraints' => [
                new Callback([$this, 'validateCategory']),
            ],
        ]);
    }

    public function validateCategory(CategoryDraft $category, ExecutionContextInterface $context): void
    {
        // Either existing coordinate or title must be provided
        $hasExistingCoordinate = !empty($category->existingListCoordinate);
        $hasTitle = !empty($category->title);

        if (!$hasExistingCoordinate && !$hasTitle) {
            $context->buildViolation('Either provide an existing list coordinate OR a title for a new category.')
                ->atPath('title')
                ->addViolation();
        }

        // Validate coordinate format if provided
        if ($hasExistingCoordinate) {
            $parts = explode(':', $category->existingListCoordinate, 3);
            if (count($parts) !== 3 || $parts[0] !== '30040') {
                $context->buildViolation('Invalid coordinate format. Expected: 30040:pubkey:slug')
                    ->atPath('existingListCoordinate')
                    ->addViolation();
            }
        }
    }
}
