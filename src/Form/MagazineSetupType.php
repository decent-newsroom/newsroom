<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\CategoryDraft;
use App\Dto\MagazineDraft;
use App\Form\DataTransformer\CommaSeparatedToArrayTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

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
            ])
            ->add('summary', TextType::class, [
                'label' => 'Description / summary',
                'required' => false,
                'empty_data' => '',
            ])
            ->add('imageUrl', TextType::class, [
                'label' => 'Logo / image URL',
                'required' => false,
                'empty_data' => '',
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
            ->add('categories', CollectionType::class, [
                'entry_type' => CategoryType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => false,
            ])
        ;

        $builder->get('tags')->addModelTransformer($this->transformer);

        // Filter out empty categories before validation and validate count after
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            if (isset($data['categories']) && is_array($data['categories'])) {
                $data['categories'] = array_values(array_filter($data['categories'], function($cat) {
                    $hasTitle = !empty($cat['title']);
                    $hasCoordinate = !empty($cat['existingListCoordinate']);
                    return $hasTitle || $hasCoordinate;
                }));
                $event->setData($data);
            }
        });

        // Validate that at least one category remains after filtering
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            $data = $form->getData();

            if ($data instanceof MagazineDraft && empty($data->categories)) {
                $form->get('categories')->addError(
                    new FormError('Please add at least one category for your magazine.')
                );
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MagazineDraft::class,
        ]);
    }
}
