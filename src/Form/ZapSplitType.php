<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\ZapSplit;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ZapSplitType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('recipient', TextType::class, [
                'label' => 'Recipient',
                'required' => true,
                'attr' => [
                    'class' => 'form-control zap-recipient',
                    'placeholder' => 'npub1... or hex pubkey'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Recipient is required']),
                ],
            ])
            ->add('relay', TextType::class, [
                'label' => 'Relay hint',
                'required' => false,
                'attr' => [
                    'class' => 'form-control zap-relay',
                    'placeholder' => 'wss://relay.example.com'
                ],
                'constraints' => [
                    new Assert\Regex([
                        'pattern' => '/^wss:\/\/.+/',
                        'message' => 'Relay must be a valid WebSocket URL starting with wss://'
                    ]),
                ],
            ])
            ->add('weight', IntegerType::class, [
                'label' => 'Weight',
                'required' => false,
                'attr' => [
                    'class' => 'form-control zap-weight',
                    'placeholder' => '1',
                    'min' => 0
                ],
                'constraints' => [
                    new Assert\PositiveOrZero(['message' => 'Weight must be 0 or greater']),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ZapSplit::class,
        ]);
    }
}

