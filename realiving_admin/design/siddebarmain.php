<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Luxury Panoramic Showcase</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <style>
    .panorama-card:hover .overlay-info {
      opacity: 1;
    }
    .panorama-card:hover .card-label {
      transform: translateY(0);
    }
    @keyframes pulse-glow {
      0%, 100% { box-shadow: 0 0 15px rgba(59, 130, 246, 0.5); }
      50% { box-shadow: 0 0 25px rgba(59, 130, 246, 0.8); }
    }
    .pulse-effect {
      animation: pulse-glow 2s infinite;
    }
  </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen">
  
  <!-- Hero Section -->
  <section class="py-16 px-4 bg-gradient-to-r from-blue-900 to-indigo-900 text-white">
    <div class="max-w-7xl mx-auto">
      <div class="text-center mb-12">
        <h1 class="text-4xl md:text-5xl font-bold mb-4">Immersive Virtual Experiences</h1>
        <p class="text-xl text-blue-100 max-w-3xl mx-auto">Explore our luxury spaces through interactive 360° panoramic views</p>
      </div>
      
      <div class="flex justify-center space-x-4 mb-8">
        <span class="inline-flex items-center px-4 py-2 rounded-full bg-blue-800 text-sm font-medium">
          <i class="fas fa-vr-cardboard mr-2"></i> Virtual Tours
        </span>
        <span class="inline-flex items-center px-4 py-2 rounded-full bg-blue-800 text-sm font-medium">
          <i class="fas fa-compass mr-2"></i> Interactive Experiences
        </span>
        <span class="inline-flex items-center px-4 py-2 rounded-full bg-blue-800 text-sm font-medium">
          <i class="fas fa-home mr-2"></i> Luxury Interiors
        </span>
      </div>
    </div>
  </section>

  <!-- Main Showcase Section -->
  <section class="py-16 px-4">
    <div class="max-w-7xl mx-auto">
      <div class="flex flex-col md:flex-row items-center justify-between mb-12">
        <div class="md:w-1/2 mb-6 md:mb-0">
          <h2 class="text-3xl font-bold text-gray-800">Explore Our Signature Spaces</h2>
          <p class="text-gray-600 mt-2">Step inside and experience the luxury of our spaces from every angle</p>
        </div>
        <div class="md:w-1/2 flex justify-end">
          <div class="relative">
            <input type="text" placeholder="Search spaces..." class="px-4 py-2 pl-10 pr-4 rounded-lg border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 w-full md:w-64">
            <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
          </div>
        </div>
      </div>

      <!-- Panorama Cards -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 md:gap-8">
        
        <!-- Panorama Card 1 -->
        <div class="panorama-card group relative bg-white rounded-xl shadow-xl overflow-hidden transition-all duration-300 hover:shadow-2xl">
          <!-- Logo Badge -->
          <div class="absolute top-4 left-4 z-20">
            <div class="bg-white/90 backdrop-blur-sm rounded-lg p-2 shadow-lg">
              <img src="/api/placeholder/150/40" alt="Logo" class="h-8">
            </div>
          </div>
          
          <!-- Room Type Badge -->
          <div class="absolute top-4 right-4 z-20">
            <span class="inline-flex items-center px-3 py-1 rounded-full bg-blue-600 text-white text-sm font-medium">
              <i class="fas fa-bed mr-1"></i> Master Bedroom
            </span>
          </div>
          
          <!-- Iframe Container -->
          <div class="relative h-80 w-full">
            <iframe src="https://kd20-realiving.yfcad.com/pano?id=61549751" allowfullscreen class="w-full h-full"></iframe>
            
            <!-- Interactive Overlay -->
            <div class="overlay-info absolute inset-0 bg-gradient-to-t from-black/70 to-transparent opacity-0 transition-all duration-300 flex flex-col justify-end p-6">
              <h3 class="text-xl font-bold text-white">Luxury Master Suite</h3>
              <p class="text-gray-200 text-sm mb-3">Experience our spacious master bedroom featuring premium finishes and elegant design.</p>
              <button class="pulse-effect w-full md:w-auto bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded-lg font-medium flex items-center justify-center">
                <i class="fas fa-eye mr-2"></i> View Full Experience
              </button>
            </div>
          </div>
          
          <!-- Card Footer -->
          <div class="p-4 border-t border-gray-100">
            <div class="flex items-center justify-between">
              <div class="flex items-center">
                <i class="fas fa-map-marker-alt text-red-500 mr-2"></i>
                <span class="text-gray-600 text-sm">Premium Collection</span>
              </div>
              <div class="flex space-x-2">
                <button class="p-2 rounded-full hover:bg-gray-100 text-gray-500 transition-colors duration-200">
                  <i class="fas fa-share-alt"></i>
                </button>
                <button class="p-2 rounded-full hover:bg-gray-100 text-gray-500 transition-colors duration-200">
                  <i class="fas fa-heart"></i>
                </button>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Panorama Card 2 -->
        <div class="panorama-card group relative bg-white rounded-xl shadow-xl overflow-hidden transition-all duration-300 hover:shadow-2xl">
          <!-- Logo Badge -->
          <div class="absolute top-4 left-4 z-20">
            <div class="bg-white/90 backdrop-blur-sm rounded-lg p-2 shadow-lg">
              <img src="/api/placeholder/150/40" alt="Logo" class="h-8">
            </div>
          </div>
          
          <!-- Room Type Badge -->
          <div class="absolute top-4 right-4 z-20">
            <span class="inline-flex items-center px-3 py-1 rounded-full bg-green-600 text-white text-sm font-medium">
              <i class="fas fa-couch mr-1"></i> Living Room
            </span>
          </div>
          
          <!-- Iframe Container -->
          <div class="relative h-80 w-full">
            <iframe src="https://kd20-realiving.yfcad.com/pano?id=61549751" allowfullscreen class="w-full h-full"></iframe>
            
            <!-- Interactive Overlay -->
            <div class="overlay-info absolute inset-0 bg-gradient-to-t from-black/70 to-transparent opacity-0 transition-all duration-300 flex flex-col justify-end p-6">
              <h3 class="text-xl font-bold text-white">Designer Living Space</h3>
              <p class="text-gray-200 text-sm mb-3">Explore our contemporary living area with custom furnishings and panoramic views.</p>
              <button class="pulse-effect w-full md:w-auto bg-green-600 hover:bg-green-700 text-white py-2 px-4 rounded-lg font-medium flex items-center justify-center">
                <i class="fas fa-eye mr-2"></i> View Full Experience
              </button>
            </div>
          </div>
          
          <!-- Card Footer -->
          <div class="p-4 border-t border-gray-100">
            <div class="flex items-center justify-between">
              <div class="flex items-center">
                <i class="fas fa-map-marker-alt text-red-500 mr-2"></i>
                <span class="text-gray-600 text-sm">Signature Series</span>
              </div>
              <div class="flex space-x-2">
                <button class="p-2 rounded-full hover:bg-gray-100 text-gray-500 transition-colors duration-200">
                  <i class="fas fa-share-alt"></i>
                </button>
                <button class="p-2 rounded-full hover:bg-gray-100 text-gray-500 transition-colors duration-200">
                  <i class="fas fa-heart"></i>
                </button>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Panorama Card 3 -->
        <div class="panorama-card group relative bg-white rounded-xl shadow-xl overflow-hidden transition-all duration-300 hover:shadow-2xl">
          <!-- Logo Badge -->
          <div class="absolute top-4 left-4 z-20">
            <div class="bg-white/90 backdrop-blur-sm rounded-lg p-2 shadow-lg">
              <img src="/api/placeholder/150/40" alt="Logo" class="h-8">
            </div>
          </div>
          
          <!-- Room Type Badge -->
          <div class="absolute top-4 right-4 z-20">
            <span class="inline-flex items-center px-3 py-1 rounded-full bg-purple-600 text-white text-sm font-medium">
              <i class="fas fa-utensils mr-1"></i> Dining Area
            </span>
          </div>
          
          <!-- Iframe Container -->
          <div class="relative h-80 w-full">
            <iframe src="https://kd20-realiving.yfcad.com/pano?id=61549751" allowfullscreen class="w-full h-full"></iframe>
            
            <!-- Interactive Overlay -->
            <div class="overlay-info absolute inset-0 bg-gradient-to-t from-black/70 to-transparent opacity-0 transition-all duration-300 flex flex-col justify-end p-6">
              <h3 class="text-xl font-bold text-white">Elegant Dining Experience</h3>
              <p class="text-gray-200 text-sm mb-3">Discover our sophisticated dining area perfect for entertaining guests in style.</p>
              <button class="pulse-effect w-full md:w-auto bg-purple-600 hover:bg-purple-700 text-white py-2 px-4 rounded-lg font-medium flex items-center justify-center">
                <i class="fas fa-eye mr-2"></i> View Full Experience
              </button>
            </div>
          </div>
          
          <!-- Card Footer -->
          <div class="p-4 border-t border-gray-100">
            <div class="flex items-center justify-between">
              <div class="flex items-center">
                <i class="fas fa-map-marker-alt text-red-500 mr-2"></i>
                <span class="text-gray-600 text-sm">Elite Collection</span>
              </div>
              <div class="flex space-x-2">
                <button class="p-2 rounded-full hover:bg-gray-100 text-gray-500 transition-colors duration-200">
                  <i class="fas fa-share-alt"></i>
                </button>
                <button class="p-2 rounded-full hover:bg-gray-100 text-gray-500 transition-colors duration-200">
                  <i class="fas fa-heart"></i>
                </button>
              </div>
            </div>
          </div>
        </div>
        
      </div>

      <!-- View More Button -->
      <div class="flex justify-center mt-12">
        <button class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium flex items-center shadow-lg hover:shadow-xl transition-all duration-300">
          <i class="fas fa-th-large mr-2"></i> View More 
        </button>
      </div>
    </div>
  </section>

  <!-- Features Section -->
  <section class="py-16 px-4 bg-gray-50">
    <div class="max-w-7xl mx-auto">
      <div class="text-center mb-12">
        <h2 class="text-3xl font-bold text-gray-800">Why Choose Our Virtual Tours</h2>
        <p class="text-gray-600 mt-2">Discover the benefits of our interactive 360° experiences</p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
        <div class="bg-white p-6 rounded-xl shadow-md">
          <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center mb-4">
            <i class="fas fa-vr-cardboard text-blue-600 text-xl"></i>
          </div>
          <h3 class="text-xl font-bold text-gray-800 mb-2">Immersive Experience</h3>
          <p class="text-gray-600">Get a true feel for the space with our high-definition panoramic views.</p>
        </div>

        <div class="bg-white p-6 rounded-xl shadow-md">
          <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center mb-4">
            <i class="fas fa-mobile-alt text-green-600 text-xl"></i>
          </div>
          <h3 class="text-xl font-bold text-gray-800 mb-2">Mobile Friendly</h3>
          <p class="text-gray-600">Explore our spaces on any device, anywhere and anytime.</p>
        </div>

        <div class="bg-white p-6 rounded-xl shadow-md">
          <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center mb-4">
            <i class="fas fa-ruler-combined text-purple-600 text-xl"></i>
          </div>
          <h3 class="text-xl font-bold text-gray-800 mb-2">Precise Details</h3>
          <p class="text-gray-600">Examine every detail with high-resolution imagery and interactive hotspots.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- CTA Section -->
  <section class="py-16 px-4 bg-gradient-to-r from-blue-600 to-indigo-700 text-white">
    <div class="max-w-7xl mx-auto text-center">
      <h2 class="text-3xl font-bold mb-4">Ready to Experience Our Spaces?</h2>
      <p class="text-xl text-blue-100 mb-8 max-w-3xl mx-auto">Schedule a personalized virtual tour session with one of our experts</p>
      <div class="flex flex-col sm:flex-row gap-4 justify-center">
        <button class="bg-white text-blue-700 hover:bg-blue-50 font-bold py-3 px-6 rounded-lg shadow-lg transition-all duration-300">
          Book a Virtual Tour
        </button>
        <button class="bg-transparent border-2 border-white text-white hover:bg-white/10 font-bold py-3 px-6 rounded-lg transition-all duration-300">
          View All Spaces
        </button>
      </div>
    </div>
  </section>

</body>
</html>