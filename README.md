# Twig Tags

Provides component-like tags for Twig in the [Hiraeth Nano Framework](https://hiraeth.dev).

## Introduction

This package is designed to solve one problem and solve it well.  Specifically, it is designed to enable you to create and use component-like templates in Twig.  The main reason you'd probably want to do this is if you're using something like Tailwind and you want to avoid writing traditional CSS classes/selectors and relying on `@apply`.  The only other present-day workaround to do something similar would be potentially writing extremely repetitive and verbose {% include %} tags.  Even then, there are limitations.  For example, there's no way to easily provide child components to a given included component in the parent scope, so you'd have to have a large set of extremely specialized components or a ton of dynamic logic inside a single included component to handle different types of data provided to it.

To solve this, Twig Tags takes advantage of Hiraeth 3.0 beta's new Renderer interface (for Twig extensions).  This provides post-processing modification of Twig templates.  From Twig's perspective, your components are just another HTML Tag, but after your template renders, the post-processing swaps those tags for their respective components using attributes similar to React props for a fully context aware solution.

## The Problem

Let's imagine a simple example.  You want to make a button component so that you can add a button-style links to your markup.  Traditionally, you'd just use markup:

```html
<a class="button" href="/route/to/resource">My Resource</a>
```

That's _relatively_ fine if you have standard CSS with class selectors.  You can litter tens, hundreds, or even thousands of these around your Twig templates.  Then if you need to update the style of a button, you just go into your CSS, find the `.button` selector, and away you go.

Enter Tailwind.  You want to use Tailwind.  So, your go-to solution is going to be to just modify your CSS to use the `@apply` directive:

```css
.button {
    @apply <my tailwind classes here>;
}
```

This works, but as things get increasingly complex, you start to realize `@apply` isn't always working like it would if those classes were added directly on the element.  Much of this is what led Tailwind's creator, Adam Wathan, to write "I can say with absolute certainty that if I started Tailwind CSS over from scratch, there would be no @apply."  It's also why `@apply` usage is heavily frowned upon.

OK... no problem... Twig to the rescue.  Let's move our button into its own file `components/button.html`:

```html
<a class="button" href="{{ href }}">{{ text }}</a>
```

...and then we'll just use `{% include ... %}` like so:

```twig
{% include 'components/button.html' with {href: "/route/to/resource", text: "My Resource"} %}
```

While this works, there are a lot of drawbacks.  Firstly, the syntax for `{% include %}` is extremely verbose.  Secondly, what happens if you have a more complex component, like a grid component where the contents of each cell in the grid need to be defined in the parent scope?  What if you need to use components within components?  You can start to see pretty easily how your daily development experience is no longer going to be working with relatively simple to parse HTML, but a complex stack of includes with sparsely embedded HTML, properties and conditions to control child element types, and generally all sorts of madness.

## The Solution

To demonstrate the abilities of Twig Tags, let's just go ahead an install it.

```
composer require hiraeth/twig-tags
```

Now we'll create two new components.  Firstly `resources/tags/atoms/title.html`:

```twig
<h2>{{ value }}</h2>
```

Secondly, a grid (`resources/tags/layout/grid.html`) :

```twig
{% if title|default(null) %}
    <t:atoms:title value={% v: title %} />
{% endif %}
<div class="grid grid-cols-12 grid-rows-none gap-4">
   {% for child in children %}
       <div class="col-span-{{ 12 / split }}">
           {{ child|raw }}
       </div>
   {% endfor %}
</div>
```

Note, in the above grid, we're doing a few interesting things.

Firstly, we're including our `title` component.  Components can easily include sub-components.  Secondly, we're passing the dynamic value of the incoming `title` attribute.  This syntax for dynamic values supports any twig expression.  Accordingly, attributes can have object values, or can be any valid Twig expression, e.g. `{% v: 2 * 2 %}`.

Secondly, we're looping over the original children from the parent scope to determine the contents of our grid, placing each child in the appropriate wrapper with a `col-span`, thereby keeping our Tailwind layout nicely encapsulated in our component, while the content can be defined elsewhere.

Lastly, we're using the incoming `split` attribute to determine, dynamically, what our `col-span` value will be.  Passing `split="2"` will therefore result in a 50/50 split with each column spanning 6 columns, while `split="4"` would result in 25/25/25/25 with each item spanning 3 columns.

Finally, let's see how we use our grid component (and by extension our title component) in our main template:

```html
<t:layout:grid split="2" title="My Grid">
    <p>First Child</p>
    <p>Second Child</p>
</t:layout:grid>
```

Simple!

## Caveats

OK, there's always a few.  But we promise, there's not that many:

1. The extension of your templates must be `.html` and `.htm` and your tags must have the same extension as the file they're included in.  This is probably already the case if you're using Hiraeth cause you're not a heathen.  Other formats may be supported down the line, but currently it explicitly looks for `.html` or `.htm` and the tag component extensions must match the template extension.
2. Lastly, due to HTML not really supporting XML namespaces, you cannot have tag components directly in `resources/tags`.  They **MUST** be in a sub-directory.  When the tag `<t:layout:grid>` is used, the first part of the tag name is stripped, the rest is translated to the path to the component.  In that respect `<tag:layout:grid>` would work just as well... but if you only have two levels, the first part will be dropped and the post-processor will not recognize it as a tag component.

That's it!

## Development

#### Code Check and Testing

Run Analysis:

```
php vendor/bin/phpstan -l7 analyse src/
```

Run Tests:

```
php vendor/bin/phpunit --bootstrap vendor/autoload.php test/cases
```
