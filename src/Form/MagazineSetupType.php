<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\MagazineDraft;
use App\Form\DataTransformer\CommaSeparatedToArrayTransformer;
use App\Util\NostrKeyUtil;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

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

        if ($options['is_admin']) {
            $builder->add('zapSplitNpub', TextType::class, [
                'label' => 'Zap split recipient (npub)',
                'required' => false,
                'empty_data' => '',
                'attr' => [
                    'placeholder' => 'npub1…',
                ],
                'help' => '100% of zaps on this magazine and its new categories will go to this npub.',
                'constraints' => [
                    new Assert\Callback(function ($value, $context) {
                        if ($value === null || $value === '') {
                            return;
                        }
                        if (!NostrKeyUtil::isNpub($value)) {
                            $context->buildViolation('Must be a valid npub (starts with npub1).')
                                ->addViolation();
                            return;
                        }
                        try {
                            NostrKeyUtil::npubToHex($value);
                        } catch (\Throwable $e) {
                            $context->buildViolation('Invalid npub: could not decode.')
                                ->addViolation();
                        }
                    }),
                ],
            ]);
        }

        $builder->get('tags')->addModelTransformer($this->transformer);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MagazineDraft::class,
            'is_admin' => false,
        ]);
    }
}
