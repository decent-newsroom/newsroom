<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\MagazineDraft;
use App\Form\DataTransformer\CommaSeparatedToArrayTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Magazine setup form — Step 1: basic magazine metadata only.
 * Categories are handled in a separate step via MagazineCategoriesType.
 */
class MagazineSetupType extends AbstractType
{
    public function __construct(private CommaSeparatedToArrayTransformer $transformer)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Magazine title',
                'required' => true,
                'attr' => [
                    'data-ui--magazine-preview-target' => 'titleInput',
                    'data-action' => 'input->ui--magazine-preview#updatePreview',
                ],
            ])
            ->add('summary', TextType::class, [
                'label' => 'Description / summary',
                'required' => false,
                'empty_data' => '',
                'attr' => [
                    'data-ui--magazine-preview-target' => 'summaryInput',
                    'data-action' => 'input->ui--magazine-preview#updatePreview',
                ],
            ])
            ->add('imageUrl', TextType::class, [
                'label' => 'Cover image URL',
                'required' => false,
                'empty_data' => '',
                'attr' => [
                    'data-ui--magazine-preview-target' => 'imageInput',
                    'data-action' => 'input->ui--magazine-preview#updatePreview',
                ],
            ])
            ->add('language', TextType::class, [
                'label' => 'Language (optional)',
                'required' => false,
                'empty_data' => '',
            ])
            ->add('tags', TextType::class, [
                'label' => 'Tags (comma separated, optional)',
                'required' => false,
                'empty_data' => '',
            ])
        ;

        $builder->get('tags')->addModelTransformer($this->transformer);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MagazineDraft::class,
        ]);
    }
}
