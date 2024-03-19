@if(config('app.debug'))
  @php
    /** @var string|null $location */
    /** @var string|null $margin */
    $location = $location ?? 'bottom-right';
    $locationClassMap = [
        'top-left' => 'top-0 left-0',
        'top-center' => 'top-0 left-1/2',
        'top-right' => 'top-0 right-0',
        'bottom-left' => 'bottom-0 left-0',
        'bottom-center' => 'bottom-0 left-1/2',
        'bottom-right' => 'bottom-0 right-0',
    ];
    $margin = $margin ?? 'sm';
    $marginMap = ['none' => 'my-[0px] mx-4', 'xs' => 'my-[5px] mx-4', 'sm' => 'my-[10px] mx-4', 'md' => 'my-[20px] mx-4', 'lg' => 'my-[40px] mx-4', 'xl' => 'my-[80px] mx-4', '2xl' => 'my-[160px] mx-4'];
    $packageJSON = json_decode(file_get_contents(base_path() . '/package.json'), false, 512, JSON_THROW_ON_ERROR);
    $tailwindVersion = $packageJSON->devDependencies->tailwindcss ?? null;
  @endphp
  <div class="fixed {{ $marginMap[$margin] }} {{ $locationClassMap[$location] }} text-xs rounded-full border border-gray-200 px-2 bg-gray-200/50 whitespace-nowrap z-50" style="@if(in_array($location, ['top-center', 'bottom-center'], true))transform: translateX(-50%)@endif">
    @if($tailwindVersion)
      <span class="py-1 px-2">tailwindcss v{{ str_replace('^', '', $tailwindVersion) }}</span>
    @endif
    <span class="py-1 rounded-full px-4 sm:px-2          text-white sm:text-black                border border-red-500/90 sm:border-0                     shadow-md shadow-red-600/50 sm:shadow-none                       bg-red-500/75 sm:bg-transparent">XS</span>
    <span class="py-1 rounded-full px-2 sm:px-4 md:px-2  text-black sm:text-white md:text-black  border-0 sm:border sm:border-amber-500/90 md:border-0    shadow-none sm:shadow-md sm:shadow-amber-600/50 md:shadow-none   bg-transparent sm:bg-amber-500/75 md:bg-transparent">SM</span>
    <span class="py-1 rounded-full px-2 md:px-4 lg:px-2  text-black md:text-white lg:text-black  border-0 md:border md:border-emerald-500/90 lg:border-0  shadow-none md:shadow-md md:shadow-emerald-600/50 lg:shadow-none bg-transparent md:bg-emerald-500/75 lg:bg-transparent">MD</span>
    <span class="py-1 rounded-full px-2 lg:px-4 xl:px-2  text-black lg:text-white xl:text-black  border-0 lg:border lg:border-sky-500/90 xl:border-0      shadow-none lg:shadow-md lg:shadow-sky-600/50 xl:shadow-none     bg-transparent lg:bg-sky-500/75 xl:bg-transparent">LG</span>
    <span class="py-1 rounded-full px-2 xl:px-4 2xl:px-2 text-black xl:text-white 2xl:text-black border-0 xl:border xl:border-purple-500/90 2xl:border-0  shadow-none xl:shadow-md xl:shadow-purple-600/50 2xl:shadow-none bg-transparent xl:bg-purple-500/75 2xl:bg-transparent">XL</span>
    <span class="py-1 rounded-full px-2 2xl:px-4         text-black 2xl:text-white               border-0 2xl:border 2xl:border-pink-500/90               shadow-none 2xl:shadow-md 2xl:shadow-pink-600/50                 bg-transparent 2xl:bg-pink-500/75">2XL+</span>
  </div>
@endif
