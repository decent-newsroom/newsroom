<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Article;
use App\Form\DataTransformer\CommaSeparatedToJsonTransformer;
use App\Form\DataTransformer\HtmlToMdTransformer;
use App\Form\Type\QuillType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EditorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // create a form with a title field, a QuillType content field and a submit button
        $builder
            ->add('title', TextType::class, [
                'required' => false,
                'sanitize_html' => true,
                'attr' => ['placeholder' => 'Awesome article', 'class' => 'form-control']])
            ->add('summary', TextareaType::class, [
                'required' => false,
                'sanitize_html' => true,
                'attr' => ['class' => 'form-control']])
            ->add('content', QuillType::class, [
                'required' => false,
                'attr' => ['placeholder' => 'Enter content', 'class' => 'form-control']])
            ->add('image', UrlType::class, [
                'required' => false,
                'label' => 'Cover image URL',
                'attr' => ['class' => 'form-control']])
            ->add('topics', TextType::class, [
                'required' => false,
                'sanitize_html' => true,
                'help' => 'Separate tags with commas, skip #',
                'attr' => ['placeholder' => 'Enter tags', 'class' => 'form-control']]);

        // Apply the custom transformer
        $builder->get('topics')
            ->addModelTransformer(new CommaSeparatedToJsonTransformer());
        $builder->get('content')
            ->addModelTransformer(new HtmlToMdTransformer());

    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Article::class,
        ]);
    }
}
