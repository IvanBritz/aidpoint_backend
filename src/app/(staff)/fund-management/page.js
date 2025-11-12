'use client'

import Header from '@/components/Header'
import { useAuth } from '@/hooks/auth'
import { useEffect, useState } from 'react'
import axios from '@/lib/axios'
import Loading from '@/components/Loading'

const FundManagement = () => {
    const { user } = useAuth({ middleware: 'auth' })
    const [allocations, setAllocations] = useState([])
    const [financialAid, setFinancialAid] = useState(null)
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState(null)
    const [showModal, setShowModal] = useState(false)
    const [editingAllocation, setEditingAllocation] = useState(null)
    
    const [formData, setFormData] = useState({
        fund_type: 'tuition',
        sponsor_name: '',
        allocated_amount: '',
        description: ''
    })

    useEffect(() => {
        if (user) {
            loadAllocations()
        }
    }, [user])

    const loadAllocations = async () => {
        try {
            setLoading(true)
            const response = await axios.get('/api/fund-allocations')
            setAllocations(response.data.data)
            setFinancialAid(response.data.financial_aid)
        } catch (err) {
            setError(err.response?.data?.message || 'Failed to load fund allocations')
        } finally {
            setLoading(false)
        }
    }

    const handleSubmit = async (e) => {
        e.preventDefault()
        
        try {
            if (editingAllocation) {
                await axios.put(`/api/fund-allocations/${editingAllocation.id}`, formData)
            } else {
                await axios.post('/api/fund-allocations', formData)
            }
            
            setShowModal(false)
            setEditingAllocation(null)
            resetForm()
            loadAllocations()
        } catch (err) {
            setError(err.response?.data?.message || 'Failed to save fund allocation')
        }
    }

    const handleEdit = (allocation) => {
        setEditingAllocation(allocation)
        setFormData({
            fund_type: allocation.fund_type,
            sponsor_name: allocation.sponsor_name,
            allocated_amount: allocation.allocated_amount,
            description: allocation.description || ''
        })
        setShowModal(true)
    }

    const handleDelete = async (id) => {
        if (!confirm('Are you sure you want to delete this fund allocation?')) return
        
        try {
            await axios.delete(`/api/fund-allocations/${id}`)
            loadAllocations()
        } catch (err) {
            setError(err.response?.data?.message || 'Failed to delete fund allocation')
        }
    }

    const resetForm = () => {
        setFormData({
            fund_type: 'tuition',
            sponsor_name: '',
            allocated_amount: '',
            description: ''
        })
    }

    const openNewModal = () => {
        setEditingAllocation(null)
        resetForm()
        setShowModal(true)
    }

    const getFundTypeLabel = (type) => {
        switch (type) {
            case 'tuition': return 'Tuition'
            case 'cola': return 'COLA'
            case 'other': return 'Other'
            default: return type
        }
    }

    const getStatusBadge = (allocation) => {
        const percentage = (allocation.utilized_amount / allocation.allocated_amount) * 100
        
        if (percentage >= 100) {
            return <span className="px-2 py-1 text-xs rounded-full bg-red-100 text-red-800">Fully Utilized</span>
        } else if (percentage >= 80) {
            return <span className="px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-800">High Usage</span>
        } else if (percentage >= 50) {
            return <span className="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800">Moderate Usage</span>
        } else {
            return <span className="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">Low Usage</span>
        }
    }

    if (loading) {
        return (
            <>
                <Header title="Fund Management" />
                <div className="py-12">
                    <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                        <Loading />
                    </div>
                </div>
            </>
        )
    }

    return (
        <>
            <Header title="Fund Management" />
            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    {error && (
                        <div className="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                            {error}
                        </div>
                    )}

                    {/* Header */}
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                        <div className="p-6 bg-white border-b border-gray-200">
                            <div className="flex justify-between items-center">
                                <div>
                                    <h2 className="text-2xl font-bold text-gray-900">Fund Management</h2>
                                    {financialAid && (
                                        <p className="text-sm text-gray-600 mt-1">{financialAid.center_name}</p>
                                    )}
                                </div>
                                <button
                                    onClick={openNewModal}
                                    className="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-md transition-colors"
                                >
                                    Add Fund Allocation
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Fund Allocations Table */}
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 bg-white">
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Fund Type
                                            </th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Sponsor Name
                                            </th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Allocated
                                            </th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Utilized
                                            </th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Remaining
                                            </th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Status
                                            </th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {allocations.length === 0 ? (
                                            <tr>
                                                <td colSpan="7" className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                                                    No fund allocations found. Click "Add Fund Allocation" to create one.
                                                </td>
                                            </tr>
                                        ) : (
                                            allocations.map((allocation) => (
                                                <tr key={allocation.id} className="hover:bg-gray-50">
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                            {getFundTypeLabel(allocation.fund_type)}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm font-medium text-gray-900">
                                                            {allocation.sponsor_name}
                                                        </div>
                                                        {allocation.description && (
                                                            <div className="text-sm text-gray-500">
                                                                {allocation.description}
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                        ₱{parseFloat(allocation.allocated_amount).toFixed(2)}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                        ₱{parseFloat(allocation.utilized_amount).toFixed(2)}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                        ₱{parseFloat(allocation.remaining_amount).toFixed(2)}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        {getStatusBadge(allocation)}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                        <button
                                                            onClick={() => handleEdit(allocation)}
                                                            className="text-indigo-600 hover:text-indigo-900 mr-4"
                                                        >
                                                            Edit
                                                        </button>
                                                        <button
                                                            onClick={() => handleDelete(allocation.id)}
                                                            className="text-red-600 hover:text-red-900"
                                                        >
                                                            Delete
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Modal */}
            {showModal && (
                <div className="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
                    <div className="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                        <div className="mt-3">
                            <h3 className="text-lg font-medium text-gray-900 mb-4">
                                {editingAllocation ? 'Edit Fund Allocation' : 'Add Fund Allocation'}
                            </h3>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700">Fund Type</label>
                                    <select
                                        value={formData.fund_type}
                                        onChange={(e) => setFormData({ ...formData, fund_type: e.target.value })}
                                        className="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                        required
                                    >
                                        <option value="tuition">Tuition</option>
                                        <option value="cola">COLA</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label className="block text-sm font-medium text-gray-700">Fund Name</label>
                                    <input
                                        type="text"
                                        value={formData.sponsor_name}
                                        onChange={(e) => setFormData({ ...formData, sponsor_name: e.target.value })}
                                        className="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                        required
                                    />
                                </div>

                                <div>
                                    <label className="block text-sm font-medium text-gray-700">Allocated Amount (₱)</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={formData.allocated_amount}
                                        onChange={(e) => setFormData({ ...formData, allocated_amount: e.target.value })}
                                        className="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                        required
                                    />
                                </div>

                                <div>
                                    <label className="block text-sm font-medium text-gray-700">Description</label>
                                    <textarea
                                        value={formData.description}
                                        onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                                        className="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                        rows="3"
                                    />
                                </div>

                                <div className="flex justify-end space-x-3 pt-4">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setShowModal(false)
                                            setEditingAllocation(null)
                                            resetForm()
                                        }}
                                        className="bg-gray-300 hover:bg-gray-400 text-gray-700 px-4 py-2 rounded-md transition-colors"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        className="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-md transition-colors"
                                    >
                                        {editingAllocation ? 'Update' : 'Create'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            )}
        </>
    )
}

export default FundManagement