<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\DataTransformer\CommaSeparatedToArrayTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MainCategoryType extends AbstractType
{
    public function __construct(private CommaSeparatedToArrayTransformer $transformer)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Title',
                'help' => 'Category display name'
            ])
            ->add('slug', TextType::class, [
                'label' => 'Slug',
                'help' => 'URL-friendly identifier (e.g., ai-ml, blockchain)',
                'required' => false,
                'attr' => [
                    'placeholder' => 'auto-generated from title if empty'
                ]
            ])
            ->add('tags', TextType::class, [
                'label' => 'Tags',
                'help' => 'Comma-separated tags for matching RSS items (e.g., artificial-intelligence, AI, machine-learning)'
            ]);

        $builder->get('tags')->addModelTransformer($this->transformer);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null, // This form maps directly to the object array
        ]);
    }
}
